<?php

namespace Tests\Unit;

use App\Services\WhatsApp\HandoffGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * حارس التحويل لموظف: دوال نقية بلا قاعدة ولا تطبيق.
 */
class HandoffGuardTest extends TestCase
{
    public static function normalized(): array
    {
        return [
            'تشكيل وهمزات' => ['أُريدُ التحدّث مع المسؤول؟', 'اريد التحدث مع المسوول'],
            'تطويل وعلامات' => ['حـــوّلني!!', 'حولني'],
            'تاء مربوطة وألف مقصورة' => ['الإدارة على', 'الاداره علي'],
            'حروف كبيرة ومسافات' => ["  Talk   TO\na HUMAN ", 'talk to a human'],
        ];
    }

    #[DataProvider('normalized')]
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, HandoffGuard::normalize($input));
    }

    public static function asksForHuman(): array
    {
        return [
            ['حولني'],
            ['حوّلني لو سمحت'],
            ['بدي أحكي مع موظف'],
            ['ممكن اتواصل مع شخص'],
            ['أريد موظف'],
            ['بدي انسان'],
            ['خلي حدا يحكي معي'],
            ['اتصلوا علي'],
            ['ما بدي احكي مع بوت'],
            ['I want to talk to a human'],
            ['Can I speak with someone?'],
            ['live agent please'],
        ];
    }

    #[Group('INV-10')]
    #[DataProvider('asksForHuman')]
    public function test_wants_human(string $text): void
    {
        $this->assertTrue(HandoffGuard::wantsHuman($text));
    }

    public static function doesNotAskForHuman(): array
    {
        return [
            ['مرحبا'],
            ['كم سعر العمرة؟'],
            ['بدي أحوّل المبلغ تحويل بنكي'],
            ['حولت المبلغ امبارح'],
            ['الموظف قال لي السعر'],
            ['إلغاء'],
            ['I want to talk about prices'],
        ];
    }

    #[DataProvider('doesNotAskForHuman')]
    public function test_does_not_want_human(string $text): void
    {
        $this->assertFalse(HandoffGuard::wantsHuman($text));
    }

    public static function claimsHandoffTexts(): array
    {
        return [
            ['تم تحويل محادثتك لأحد زملائنا'],
            ['حوّلت طلبك للقسم المختص'],
            ['حوّلتك للموظف المختص'],
            ['سيتواصل معك أحد زملائنا قريباً'],
            ['رح يتواصل معك موظف'],
            ["I've transferred your chat"],
            ['A colleague will contact you shortly'],
            ['Someone will get back to you soon'],
        ];
    }

    #[Group('INV-10')]
    #[DataProvider('claimsHandoffTexts')]
    public function test_claims_handoff(string $text): void
    {
        $this->assertTrue(HandoffGuard::claimsHandoff($text));
    }

    public static function noHandoffClaim(): array
    {
        return [
            ['أهلاً بك، كيف أخدمك؟'],
            ['يمكنك التواصل معنا على رقم الشركة'],
            ['عروض العمرة تبدأ من 450 ديناراً للفرد'],
            ['How can I help you today?'],
        ];
    }

    #[DataProvider('noHandoffClaim')]
    public function test_does_not_claim_handoff(string $text): void
    {
        $this->assertFalse(HandoffGuard::claimsHandoff($text));
    }

    /** رسالة التأكيد التي يبنيها الكود تُعدّ زعماً للتحويل — فلو أعادها النموذج بلا أداة لحوّلها الحارس */
    public function test_code_built_confirmation_is_itself_recognised_as_a_handoff_claim(): void
    {
        $this->assertTrue(HandoffGuard::claimsHandoff(HandoffGuard::confirmation(false)));
        $this->assertTrue(HandoffGuard::claimsHandoff(HandoffGuard::confirmation(true)));
    }

    public function test_is_latin_picks_the_dominant_script(): void
    {
        $this->assertTrue(HandoffGuard::isLatin('Hello, I need help'));
        $this->assertFalse(HandoffGuard::isLatin('مرحبا'));
        $this->assertFalse(HandoffGuard::isLatin('مرحبا، بدي عمرة hi'));
        $this->assertFalse(HandoffGuard::isLatin('12345'));
        $this->assertFalse(HandoffGuard::isLatin(''));
    }

    public function test_confirmation_language_follows_the_flag(): void
    {
        $this->assertFalse(HandoffGuard::isLatin(HandoffGuard::confirmation(false)));
        $this->assertTrue(HandoffGuard::isLatin(HandoffGuard::confirmation(true)));
    }
}
