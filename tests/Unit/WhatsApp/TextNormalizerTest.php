<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\TextNormalizer;
use PHPUnit\Framework\TestCase;

/** تطبيع البحث والأسماء غير الموثوقة (E11.3) */
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

    public function test_untrusted_names_lose_control_and_direction_characters(): void
    {
        $this->assertSame('أحمد evil', TextNormalizer::untrustedName("أحمد\u{202E} evil\u{0007}"));
        $this->assertSame('نون', TextNormalizer::untrustedName("  نون\n"), 'لا يُقطع بايت «ن»');
        $this->assertSame(str_repeat('ب', 10), TextNormalizer::untrustedName(str_repeat('ب', 50), 10));
        $this->assertNull(TextNormalizer::untrustedName("\u{200F}\u{200E}"));
        $this->assertNull(TextNormalizer::untrustedName(['not', 'a', 'string']));
    }
}
