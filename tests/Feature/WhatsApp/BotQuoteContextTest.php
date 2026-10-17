<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Offer;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * سياق البوت (E33.11): بطاقة العرض تحمل رقم عرضها في payload._meta.offer_id، فإن اقتبسها العميل («كم سعر هذا؟») رأى
 * النموذج «[ردّاً على بطاقة العرض رقم N: …]» وعرف العرض بعينه. الاقتباس من تفاعل يُتجاهل.
 */
class BotQuoteContextTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->fakeGraph();
        // ميتا تجلب البطاقة برابط https عام
        config(['filesystems.disks.public.url' => 'https://nusk.test/storage']);
        $this->conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'أريد عروض العمرة']);
    }

    private function context(): string
    {
        $messages = (new \ReflectionMethod(BotEngine::class, 'buildMessages'))->invoke(null, $this->conv->fresh(), WaBotSetting::current(), null);

        return implode("\n", array_column($messages, 'text'));
    }

    public function test_a_quoted_offer_card_names_the_offer_for_the_model(): void
    {
        $offer = Offer::factory()->create(['custom_card_path' => 'offer-cards/test-card.png', 'title' => 'عمرة رمضان المبارك — فندق قريب من الحرم مع إفطار يومي']);

        $out = BotTools::execute('send_offer_card', ['offer_id' => $offer->id], $this->conv);
        $this->assertTrue($out['sent'] ?? false, json_encode($out, JSON_UNESCAPED_UNICODE));
        $card = WaMessage::where('direction', 'out')->where('msg_type', 'image')->sole();
        $this->assertSame($offer->id, data_get($card->payload, '_meta.offer_id'));

        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'reply_to_wamid' => $card->wamid, 'body' => 'كم سعر هذا؟']);

        $this->assertStringContainsString("[ردّاً على بطاقة العرض رقم {$offer->id}: «".mb_substr($offer->title, 0, 60).'»] كم سعر هذا؟', $this->context());
        $this->assertStringContainsString('[ردّاً على بطاقة العرض رقم N]', BotEngine::SYSTEM_RULES);
    }

    public function test_a_plain_quote_keeps_the_eighty_character_snippet_and_a_quoted_reaction_is_ignored(): void
    {
        WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.TXT', 'body' => 'العروض المتاحة: عمرة رمضان']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.R', 'msg_type' => 'reaction', 'body' => '👍']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'reply_to_wamid' => 'wamid.TXT', 'body' => 'هذا']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'reply_to_wamid' => 'wamid.R', 'body' => 'وذاك']);

        $context = $this->context();

        $this->assertStringContainsString('[ردّاً على: «العروض المتاحة: عمرة رمضان»] هذا', $context);
        $this->assertStringContainsString("\nوذاك", $context);
        $this->assertStringNotContainsString('«👍»', $context);
    }
}
