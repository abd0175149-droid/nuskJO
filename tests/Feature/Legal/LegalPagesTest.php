<?php

namespace Tests\Feature\Legal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** الصفحتان القانونيتان تطابقان الكود (E13.9): الاستلام الآلي، والاحتفاظ، وحق الاطّلاع، وما يبقى ولماذا */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_data_deletion_page_describes_what_the_code_does(): void
    {
        $this->get('/data-deletion')->assertOk()
            ->assertSee('حذف بياناتي')
            ->assertSee('تأكيد استلام فوري')
            ->assertSee('قائمة الإيقاف')
            ->assertSee('المرتبطة بفاتورة')
            ->assertSee('نعيد تطبيق كل طلبات الحذف')
            ->assertSee('immediate acknowledgement');
    }

    public function test_the_privacy_page_states_retention_and_access(): void
    {
        $this->get('/privacy')->assertOk()
            ->assertSee('حتى 24 شهراً من آخر تواصل، ما لم ترتبط بفاتورة')
            ->assertSee('12 شهراً، ثم تُجمَّع')
            ->assertSee('حتى 6 أشهر')
            ->assertSee('خلال 30 يوماً بعد التحقّق من هويتك')
            ->assertSee('unless attached to an invoice');
    }
}
