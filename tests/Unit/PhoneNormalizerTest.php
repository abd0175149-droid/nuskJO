<?php

namespace Tests\Unit;

use App\Services\WhatsApp\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * توحيد الأرقام بين صيغ الإدخال في نُسك وصيغة ميتا (E.164 بلا +).
 * الأرقام هنا وهمية.
 */
class PhoneNormalizerTest extends TestCase
{
    public function test_digits_strips_everything_but_digits(): void
    {
        $this->assertSame('962790000123', PhoneNormalizer::digits('+962 79-000 0123'));
        $this->assertSame('', PhoneNormalizer::digits(null));
    }

    public static function canonicalForms(): array
    {
        return [
            'دولي بـ +' => ['+962790000123', '962790000123'],
            'دولي بـ 00' => ['00962790000123', '962790000123'],
            'محلي أردني' => ['0790000123', '962790000123'],
            'أردني بلا صفر' => ['790000123', '962790000123'],
            'محلي سعودي' => ['0550000123', '966550000123'],
            'سعودي بلا صفر' => ['550000123', '966550000123'],
            'كما ترسله ميتا' => ['16505551234', '16505551234'],
            'بمسافات وشرطات' => ['079 000-0123', '962790000123'],
            'فارغ' => ['', ''],
            'null' => [null, ''],
        ];
    }

    #[DataProvider('canonicalForms')]
    public function test_canonical(?string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::canonical($input));
    }

    public function test_tail_keeps_the_last_nine_digits(): void
    {
        $this->assertSame('790000123', PhoneNormalizer::tail('+962 79 000 0123'));
        $this->assertSame('0123', PhoneNormalizer::tail('0123'));
        $this->assertSame('0123', PhoneNormalizer::tail('+962790000123', 4));
    }

    public function test_same_matches_on_the_last_nine_digits_across_formats(): void
    {
        $this->assertTrue(PhoneNormalizer::same('0790000123', '+962790000123'));
        $this->assertTrue(PhoneNormalizer::same('00962790000123', '962790000123'));
        $this->assertFalse(PhoneNormalizer::same('0790000123', '0790000124'));
        $this->assertFalse(PhoneNormalizer::same('', ''));
        $this->assertFalse(PhoneNormalizer::same(null, '0790000123'));
    }
}
