<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\HandoffGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** «حذف بياناتي» تُعرف بالكود (E13.4)، ولا تُخلط بإلغاء حجز */
class HandoffGuardErasureTest extends TestCase
{
    public static function phrases(): array
    {
        return [
            'arabic' => ['حذف بياناتي', true], 'imperative plural' => ['احذفو معلوماتي', true], 'diacritics' => ['احْذِفُوا بياناتي', true],
            'all' => ['امسحوا كل بياناتي لو سمحتم', true], 'english' => ['delete my data', true], 'english all of' => ['Please erase all of my data', true],
            'booking' => ['بدي احذف الحجز', false], 'cancel' => ['الغاء', false], 'question' => ['هل تحذفون البيانات؟', false],
        ];
    }

    #[DataProvider('phrases')]
    public function test_it_recognises_only_requests_to_erase_personal_data(string $text, bool $expected): void
    {
        $this->assertSame($expected, HandoffGuard::wantsErasure($text));
    }

    public function test_the_acknowledgement_never_claims_the_data_is_already_deleted(): void
    {
        $this->assertStringContainsString('استلمنا طلب حذف بياناتك', HandoffGuard::erasureConfirmation(false));
        $this->assertStringContainsString('within 30 days', HandoffGuard::erasureConfirmation(true));
        $this->assertStringNotContainsString('تم حذف', HandoffGuard::erasureConfirmation(false));
    }
}
