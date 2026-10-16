<?php

namespace Tests\Feature\Legal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الصفحتان القانونيتان والتصنيفات والترجمة (E29.11): سطر «ترجمة الرسائل عند طلب الموظف» يظهر ما دام العلم مفعّلاً،
 * والتصنيفات والملاحظات الداخلية تُحذف مع المحادثة.
 */
class TranslationPrivacyTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_page_names_staff_translation_only_while_it_is_on(): void
    {
        $this->get('/privacy')->assertOk()
            ->assertDontSee('وترجمة الرسائل عند طلب الموظف')
            ->assertDontSee('to translate messages when a staff member asks')
            ->assertSee('التصنيفات والملاحظات الداخلية')
            ->assertSee('Internal labels and notes: deleted with the conversation');

        config(['features.env.staff_translation' => true]);
        $this->get('/privacy')->assertOk()
            ->assertSee('وترجمة الرسائل عند طلب الموظف')
            ->assertSee('to translate messages when a staff member asks');
    }

    public function test_the_deletion_page_includes_internal_labels(): void
    {
        $this->get('/data-deletion')->assertOk()
            ->assertSee('الملاحظات والتصنيفات الداخلية المحفوظة عنك')
            ->assertSee('stored notes and internal labels about you');
    }
}
