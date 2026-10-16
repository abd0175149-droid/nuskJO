<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\NumberGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * حارس الأرقام (E29.9). الحالات نفسها في tests/js/whatsapp/numberGuard.test.js لضمان التكافؤ بين الخادم والمتصفح.
 */
class NumberGuardTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function pairs(): array
    {
        return [
            'أرقام هندية وتاريخ' => ['١٥٠ دينار في ٢٠/١١', '150 JOD on 20/11', true],
            'رقم مقلوب' => ['150', '105', false],
            'فاصل الآلاف' => ['1,500', '1500', true],
            'الفاصلة العشرية العربية' => ['٢٫٥', '2.5', true],
            'أرقام فارسية' => ['۱۵۰ تومان', '150 toman', true],
            'الأصفار البادئة' => ['07/11', '7/11', true],
            'رقم زائد' => ['السعر 150', 'Price 150 for 2', false],
            'التكرار يُعدّ' => ['150 و150', '150', false],
            'المسافة الضيقة' => ["1\u{202F}500", '1500', true],
            'ليس فاصل آلاف' => ['11,20', '1120', false],
            'رقم بالحروف' => ['150', 'one hundred fifty', false],
        ];
    }

    #[DataProvider('pairs')]
    public function test_compare(string $source, string $translated, bool $ok): void
    {
        $this->assertSame($ok, NumberGuard::compare($source, $translated)['ok']);
    }

    public function test_normalize_and_extract(): void
    {
        $this->assertSame('1500 و2.5 و1500000', NumberGuard::normalize('١٬٥٠٠ و٢٫٥ و1,500,000'));
        $this->assertSame(['0', '0.5', '11', '150', '20', '7'], NumberGuard::extract('0 و0.5 و007 و150 في 20/11'));
    }

    public function test_spelled_numbers_by_language_and_their_exclusions(): void
    {
        $this->assertTrue(NumberGuard::hasSpelledNumbers('one hundred fifty'));
        $this->assertSame(['one', 'hundred', 'fifty'], NumberGuard::spelledWords('One hundred FIFTY', 'en'));
        $this->assertTrue(NumberGuard::hasSpelledNumbers('lima ratus ribu', 'id'));
        $this->assertTrue(NumberGuard::hasSpelledNumbers('iki yüz', 'tr'));
        $this->assertTrue(NumberGuard::hasSpelledNumbers('deux cents euros', 'fr'));
        $this->assertTrue(NumberGuard::hasSpelledNumbers('تین سو روپے', 'ur'));
        $this->assertTrue(NumberGuard::hasSpelledNumbers('والخمسة', 'ar'));
        // أدوات التنكير والكلمات الشائعة بمعنى آخر ليست أرقاماً
        $this->assertFalse(NumberGuard::hasSpelledNumbers('Une chambre pour un client', 'fr'));
        $this->assertFalse(NumberGuard::hasSpelledNumbers('Bir oda ayırdık', 'tr'));
        $this->assertFalse(NumberGuard::hasSpelledNumbers('یک اتاق نه', 'fa'));
        // حدود الكلمة: someone وoften ليستا one وten
        $this->assertFalse(NumberGuard::hasSpelledNumbers('Someone will often call', 'en'));
        // لغة الهدف وحدها: on التركية (عشرة) ليست on الإنجليزية، وبلا لغة تُفحص الإنجليزية والعربية
        $this->assertTrue(NumberGuard::hasSpelledNumbers('on gün', 'tr'));
        $this->assertFalse(NumberGuard::hasSpelledNumbers('travel on Friday', 'en'));
        $this->assertFalse(NumberGuard::hasSpelledNumbers('travel on Friday'));
        $this->assertTrue(NumberGuard::hasSpelledNumbers('ثلاثة أيام'));
    }

    public function test_compare_reports_what_is_missing_extra_and_spelled(): void
    {
        $this->assertSame(['ok' => false, 'missing' => ['150'], 'extra' => ['105'], 'spelled' => false, 'spelled_words' => [],
            'source' => ['11', '150', '20'], 'translated' => ['105', '11', '20']],
            NumberGuard::compare('السعر 150 في 20/11', 'Price 105 on 20/11', 'en'));
    }
}
