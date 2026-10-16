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
 * طلبات كتالوج ميتا وأسئلة منتجاته (E36، C043): لا كتالوج عندنا، فإن وصل طلب order أو سؤال بـ referred_product (كتالوج
 * رُبط بالحساب من خارج النظام) يُحفظ بنص مقروء ويصل موظفاً بسببه، ولا يرد عليه البوت. لا تُقرأ معرّفات المنتجات.
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

    private function send(array $message): void
    {
        $payload = json_decode(file_get_contents($this->fixturePath('text.json')), true);
        data_set($payload, 'entry.0.changes.0.value.messages.0', $message + ['from' => '16505551234', 'id' => 'wamid.C'.uniqid(), 'timestamp' => (string) now()->timestamp]);
        $this->postRawWebhook(json_encode($payload))->assertOk();
    }

    public function test_a_catalog_order_reaches_staff_not_the_bot(): void
    {
        $this->send(['type' => 'order', 'order' => ['product_items' => [['quantity' => 1], ['quantity' => 2]], 'text' => 'للعائلة']]);

        $conv = WaConversation::sole();
        $this->assertSame('🛒 طلب من الكتالوج: 2 أصناف — للعائلة', WaMessage::sole()->body);
        $this->assertTrue($conv->needs_attention);
        $this->assertSame('catalog', $conv->attention_reason);
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
    }

    public function test_a_question_about_a_catalog_product_is_marked_and_reaches_staff(): void
    {
        $this->send(['type' => 'text', 'text' => ['body' => 'هل هذا متوفر؟'], 'context' => ['from' => '15550783881', 'id' => 'wamid.PRODUCT', 'referred_product' => []]]);

        $this->assertSame('🛍️ [سؤال عن منتج من كتالوج واتساب] هل هذا متوفر؟', WaMessage::sole()->body);
        $this->assertSame('catalog', WaConversation::sole()->attention_reason);
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
    }
}
