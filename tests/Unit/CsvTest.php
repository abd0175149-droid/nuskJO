<?php

namespace Tests\Unit;

use App\Support\Csv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** CSV محمي من حقن الصيغ (E09.12)، بالقاعدة نفسها في resources/js/utils/csv.js (tests/js/csv.test.mjs). */
class CsvTest extends TestCase
{
    public static function dangerous(): array
    {
        return array_map(fn ($v) => [$v], ['=HYPERLINK("http://x")', '+1+1', '-2+3', '@SUM(A1)', '  =1+1', "\t=1", "\r=1", "\n=1"]);
    }

    #[DataProvider('dangerous')]
    public function test_formula_like_text_gets_a_leading_apostrophe(string $value): void
    {
        $this->assertSame("'".$value, Csv::cell($value));
    }

    public function test_numbers_and_numeric_text_are_untouched(): void
    {
        $this->assertSame(-12.5, Csv::cell(-12.5));
        $this->assertSame(7, Csv::cell(7));
        $this->assertSame('-12.50', Csv::cell('-12.50'));
        $this->assertSame('عمرة', Csv::cell('عمرة'));
        $this->assertSame('', Csv::cell(null));
        $this->assertSame('1', Csv::cell(true));
    }

    public function test_put_writes_bom_and_escaped_rows(): void
    {
        $h = fopen('php://memory', 'w+');
        Csv::bom($h);
        Csv::put($h, ['=cmd', 'a,b', -3.5, 'say "hi"']);
        rewind($h);

        $this->assertSame("\xEF\xBB\xBF'=cmd,\"a,b\",-3.5,\"say \"\"hi\"\"\"\n", stream_get_contents($h));
    }
}
