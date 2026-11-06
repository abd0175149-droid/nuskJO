<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * طلبات كتالوج واتساب وأسئلة منتجاته (E36، C043): لا كتالوج عندنا، فإن وصل طلب order أو سؤال عن منتج (product، كتالوج
 * رُبط بالرقم من خارج النظام) يُحفظ بنص مقروء ويصل موظفاً بسببه، ولا يرد عليه البوت. لا تُقرأ معرّفات المنتجات.
 */
class CatalogInboundTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
    }

    private function send(array $over): void
    {
        $this->postOpenWa('message.received', $this->incoming('16505551234', '', $over))->assertOk();
    }

    public function test_a_catalog_order_reaches_staff_not_the_bot(): void
    {
        $this->send(['type' => 'order', 'orderId' => '9912', 'body' => '']);

        $conv = WaConversation::sole();
        $this->assertSame('🛒 طلب من الكتالوج', WaMessage::sole()->body);
        $this->assertTrue($conv->needs_attention);
        $this->assertSame('catalog', $conv->attention_reason);
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
    }

    public function test_a_question_about_a_catalog_product_is_marked_and_reaches_staff(): void
    {
        $this->send(['type' => 'product', 'product' => ['title' => 'باقة العمرة', 'productId' => 'P-1']]);

        $this->assertSame('🛍️ [سؤال عن منتج من كتالوج واتساب] باقة العمرة', WaMessage::sole()->body);
        $this->assertStringNotContainsString('P-1', WaMessage::sole()->body);
        $this->assertSame('catalog', WaConversation::sole()->attention_reason);
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
    }
}
