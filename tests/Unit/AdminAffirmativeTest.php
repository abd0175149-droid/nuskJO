<?php

namespace Tests\Unit;

use App\Services\WhatsApp\AdminTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * موافقة الموظف على إجراء معلّق: صريحة، وفي أول الرسالة.
 */
class AdminAffirmativeTest extends TestCase
{
    public static function affirmative(): array
    {
        return [
            ['تأكيد'],
            ['نعم'],
            ['أكّد'],
            ['اكدت'],
            ['موافق'],
            ['أيوه'],
            ['تمام، نفّذ'],
            ['ok'],
            ['OK تمام'],
            ['okay?'],
            ['Yes'],
            ['confirm'],
        ];
    }

    #[Group('INV-13')]
    #[DataProvider('affirmative')]
    public function test_explicit_approval_is_affirmative(string $text): void
    {
        $this->assertTrue(AdminTools::isAffirmative($text));
    }

    public static function notAffirmative(): array
    {
        return [
            'رفض' => ['لا'],
            'إلغاء' => ['إلغاء'],
            'نفي قبل الموافقة' => ['لا تأكيد'],
            'تردد' => ['مش متأكد'],
            'موافقة ليست أول الرسالة' => ['هل أؤكد؟'],
            'كلمة تبدأ بحروف الموافقة' => ['نعمل التعديل بكرة'],
            'كلمة إنجليزية تبدأ بـ yes' => ['yesterday'],
            'إلغاء بالإنجليزية' => ['cancel'],
            'فارغ' => [''],
        ];
    }

    #[Group('INV-13')]
    #[DataProvider('notAffirmative')]
    public function test_anything_else_is_not_affirmative(string $text): void
    {
        $this->assertFalse(AdminTools::isAffirmative($text));
    }
}
