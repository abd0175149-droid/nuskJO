<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\TextNormalizer;
use PHPUnit\Framework\TestCase;

/** تطبيع البحث والأسماء غير الموثوقة (E11.3)، وخريطة مواضع الإبراز (E30) */
class TextNormalizerTest extends TestCase
{
    public function test_search_text_folds_arabic_variants_digits_and_spaces(): void
    {
        $this->assertSame('عمره رمضان 2027', TextNormalizer::forSearch('عُمْرَة   رمضـــان ٢٠٢٧'));
        $this->assertSame('اسامه ابو علي', TextNormalizer::forSearch('أسامة أبو علي'));
        $this->assertSame('مستشفي مسوول', TextNormalizer::forSearch('مستشفى مسؤول'));
        $this->assertSame('visa', TextNormalizer::forSearch('VISA'));
        $this->assertNull(TextNormalizer::forSearch(null));
    }

    public function test_the_position_map_matches_for_search_and_points_into_the_original(): void
    {
        $samples = ['', 'عُمْرَة   رمضـــان ٢٠٢٧', '  أسامة أبو علي  ', "سطر\n\tثانٍ", 'VISA مسؤول مستشفى', "a \u{064B} b", "\u{00A0}نص\u{00A0}"];
        foreach ($samples as $text) {
            $mapped = TextNormalizer::forSearchWithMap($text);
            $this->assertNotNull($mapped, $text);
            $this->assertSame((string) TextNormalizer::forSearch($text), $mapped['text'], $text);
            $this->assertCount(mb_strlen($mapped['text']), $mapped['map']);
        }

        $mapped = TextNormalizer::forSearchWithMap('العُمرة');
        $this->assertSame('العمره', $mapped['text']);
        $this->assertSame([0, 1, 2, 4, 5, 6], $mapped['map'], 'الضمة (الموضع 3) لا تنتج محرفاً');
        $this->assertSame(1, TextNormalizer::VERSION);
    }

    public function test_untrusted_names_lose_control_and_direction_characters(): void
    {
        $this->assertSame('أحمد evil', TextNormalizer::untrustedName("أحمد\u{202E} evil\u{0007}"));
        $this->assertSame('نون', TextNormalizer::untrustedName("  نون\n"), 'لا يُقطع بايت «ن»');
        $this->assertSame(str_repeat('ب', 10), TextNormalizer::untrustedName(str_repeat('ب', 50), 10));
        $this->assertNull(TextNormalizer::untrustedName("\u{200F}\u{200E}"));
        $this->assertNull(TextNormalizer::untrustedName(['not', 'a', 'string']));
    }
}
