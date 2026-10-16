<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\Search\SearchQuery;
use App\Services\WhatsApp\Search\Snippet;
use PHPUnit\Framework\TestCase;

/**
 * مقتطف البحث (E30.3): الإبراز على النص الأصلي بتشكيله وهمزاته، ونص العميل يعود أجزاء نصية كما هو (Vue يهرّبه)،
 * ونافذة حول أول تطابق بلا محارف تحكم ولا اتجاه.
 */
class SnippetSegmentsTest extends TestCase
{
    private function hl(array $segments): array
    {
        return array_values(array_map(fn ($s) => $s['t'], array_filter($segments, fn ($s) => $s['hl'])));
    }

    private function text(array $segments): string
    {
        return implode('', array_column($segments, 't'));
    }

    public function test_highlight_lands_on_the_original_spelling(): void
    {
        $segments = Snippet::segments('نريد العُمرة في رمضان مع أسامة', ['العمره', 'عمره', 'اسامه']);

        $this->assertSame(['العُمرة', 'أسامة'], $this->hl($segments));
        $this->assertSame('نريد العُمرة في رمضان مع أسامة', $this->text($segments));
    }

    public function test_a_partial_word_and_arabic_indic_digits_are_highlighted(): void
    {
        $this->assertSame(['فندق'], $this->hl(Snippet::segments('والفندق قريب من الحرم', ['فندق'])));
        $this->assertSame(['١٥٠'], $this->hl(Snippet::segments('السعر ١٥٠ ديناراً للشخص', ['150'])));
    }

    public function test_trailing_diacritics_stay_with_the_highlighted_letters(): void
    {
        $this->assertSame(['عُمْرَةٌ'], $this->hl(Snippet::segments('هذه عُمْرَةٌ مباركة', ['عمره'])));
    }

    public function test_customer_markup_is_plain_text_inside_segments(): void
    {
        $body = 'انظر <img src=x onerror=alert(1)> عمرة <script>alert(2)</script>';
        $segments = Snippet::segments($body, ['عمره']);

        $this->assertSame($body, $this->text($segments));
        $this->assertSame(['عمرة'], $this->hl($segments));
        foreach ($segments as $s) {
            $this->assertSame(['t', 'hl'], array_keys($s));
            $this->assertIsString($s['t']);
        }
    }

    public function test_long_text_is_windowed_around_the_first_match(): void
    {
        $body = str_repeat('كلام كثير ', 30).'التأشيرة جاهزة'.str_repeat(' وكلام بعدها', 30);
        $segments = Snippet::segments($body, ['تاشيره']);

        $this->assertSame('…', mb_substr($segments[0]['t'], 0, 1));
        $this->assertSame('…', mb_substr(end($segments)['t'], -1));
        $this->assertSame(['تأشيرة'], $this->hl($segments), 'الاستعلام «تاشيره» بلا «ال»');
        $this->assertLessThan(160, mb_strlen($this->text($segments)));
    }

    public function test_control_and_direction_characters_are_dropped_and_lines_joined(): void
    {
        $segments = Snippet::segments("سطر أول\nفيه عمرة\u{202E} مقلوبة\u{0007}", ['عمره']);

        $this->assertSame('سطر أول فيه عمرة مقلوبة', $this->text($segments));
    }

    public function test_without_a_match_the_start_is_shown_plain(): void
    {
        $segments = Snippet::segments(str_repeat('أ', 200), ['zzz']);

        $this->assertCount(1, $segments);
        $this->assertFalse($segments[0]['hl']);
        $this->assertSame(141, mb_strlen($segments[0]['t']), '140 محرفاً ثم …');
        $this->assertSame([], Snippet::segments('', ['x']));
    }

    public function test_query_terms_feed_the_highlighter(): void
    {
        $q = SearchQuery::parse('العُمرة فندق', false);

        $this->assertSame(['العمرة', 'فندق'], $this->hl(Snippet::segments('العمرة مع الفندق', $q->highlight)));
    }
}
