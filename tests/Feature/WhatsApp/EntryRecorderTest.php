<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\Offer;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaConversationEntry;
use App\Models\WaMessage;
use App\Models\WaQrLink;
use App\Services\WhatsApp\BotEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * مصدر المحادثة (E38.5): مرجع [N-45/Q7] في رسالة موقّعة يسجّل صف دخول ويسم موضوع العرض حتمياً ويوكل مختصّه،
 * والإعلان يُسجَّل بعنوانه المنقّى بلا ctwa_clid، والتكرار خلال 24 ساعة لا يضيف صفاً، وأي فشل لا يمس الاستقبال،
 * وبطاقة البوت تقول من أين جاء العميل بنص يبنيه الكود.
 */
class EntryRecorderTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private Offer $offer;

    private User $specialist;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        $this->fakeGraph();
        config(['features.env.entry_tracking' => true]);
        $this->offer = Offer::factory()->create(['id' => 45, 'title' => 'عمرة رجب', 'category' => 'umrah']);
        WaQrLink::forceCreate(['id' => 7, 'offer_id' => 45, 'channel' => 'print', 'code' => 'ABC7', 'prefilled_message' => 'x', 'status' => 'active']);
        $this->specialist = User::factory()->create();
        DB::table('wa_topic_users')->insert(['topic' => 'umrah', 'user_id' => $this->specialist->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function textWebhook(string $body, string $wamidSuffix)
    {
        $raw = json_decode(file_get_contents($this->fixturePath('text.json')), true);
        $raw['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = $body;
        $raw['entry'][0]['changes'][0]['value']['messages'][0]['id'] .= $wamidSuffix;

        return $this->postRawWebhook(json_encode($raw, JSON_UNESCAPED_UNICODE));
    }

    public function test_a_scanned_code_records_the_entry_tags_the_topic_and_assigns_the_specialist(): void
    {
        $this->textWebhook('مرحباً، أريد تفاصيل عرض «عمرة رجب» [N-45/Q7]', 'a')->assertOk();

        $entry = WaConversationEntry::sole();
        $this->assertSame(['qr', 45, 7], [$entry->channel, $entry->offer_id, $entry->qr_link_id]);
        $conv = WaConversation::sole();
        $this->assertSame('qr', DB::table('wa_conversation_topics')->where('conversation_id', $conv->id)->where('topic', 'umrah')->value('source'));
        $this->assertSame($this->specialist->id, (int) $conv->fresh()->assigned_to);

        // رسالة ثانية بالمرجع نفسه خلال 24 ساعة
        $this->textWebhook('[N-45/Q7] مرة أخرى', 'b')->assertOk();
        $this->assertSame(1, WaConversationEntry::count());
    }

    public function test_a_code_of_another_offer_is_dropped_and_unknown_offers_record_nothing(): void
    {
        Offer::factory()->create(['id' => 46, 'category' => 'visa']);

        $this->textWebhook('[N-46/Q7]', 'c')->assertOk();
        $e = WaConversationEntry::sole();
        $this->assertSame(['link', 46, null], [$e->channel, $e->offer_id, $e->qr_link_id]);

        WaConversationEntry::query()->delete();
        $this->textWebhook('[N-9999]', 'd')->assertOk();
        $this->assertSame(0, WaConversationEntry::count());
    }

    public function test_an_ad_referral_is_recorded_without_the_click_id_and_with_a_clean_headline(): void
    {
        $this->postMetaWebhook('text_with_referral.json')->assertOk();

        $e = WaConversationEntry::sole();
        $this->assertSame(['ad', 'ad', '120208392884470555', 45], [$e->channel, $e->referral_source_type, $e->referral_source_id, $e->offer_id]);
        $this->assertSame('عمرة رجب — احجز الآن', $e->referral_headline, 'محرف الاتجاه محذوف');
        $this->assertStringNotContainsString('ARAkLkA8', json_encode(DB::table('wa_conversation_entries')->get()));
        $this->assertSame('ad', DB::table('wa_conversation_topics')->value('source'));
    }

    public function test_a_recorder_failure_never_breaks_intake_or_the_reply(): void
    {
        Schema::drop('wa_conversation_topics');

        $this->textWebhook('[N-45/Q7]', 'e')->assertOk();

        $this->assertSame(1, WaMessage::where('direction', 'in')->count());
        Queue::assertPushed(ProcessWhatsAppReply::class);
    }

    public function test_nothing_is_recorded_while_the_flag_is_off(): void
    {
        config(['features.env.entry_tracking' => false]);

        $this->textWebhook('[N-45/Q7]', 'f')->assertOk();

        $this->assertSame(0, WaConversationEntry::count());
        $this->assertSame(0, DB::table('wa_conversation_topics')->count());
    }

    public function test_the_thread_shows_where_the_conversation_came_from(): void
    {
        $this->textWebhook('[N-45/Q7]', 'h')->assertOk();
        $conv = WaConversation::sole();

        $this->actingAs(User::factory()->admin()->create())->getJson("/api/whatsapp/conversations/{$conv->id}/messages")->assertOk()
            ->assertJsonPath('conversation.entry.channel', 'qr')->assertJsonPath('conversation.entry.title', 'عمرة رجب');
    }

    public function test_the_bot_card_names_the_offer_or_apologises_for_a_closed_one(): void
    {
        $card = fn (WaConversation $c) => (new \ReflectionMethod(BotEngine::class, 'customerCard'))->invoke(null, $c);
        $this->textWebhook('أريد العرض [N-45/Q7]', 'g')->assertOk();
        $conv = WaConversation::sole();

        $text = $card($conv);
        $this->assertStringContainsString('مصدر المحادثة: رمز أو رابط العرض رقم 45 («عمرة رجب»)', $text);
        $this->assertStringContainsString('get_offer_details', $text);
        $this->assertStringNotContainsString('أريد العرض', $text, 'لا نص العميل');
        $this->assertStringNotContainsString('16505551234', $text, 'ولا رقمه');

        $this->offer->forceFill(['is_active' => false])->save();
        $this->assertStringContainsString('طلب العميل العرض رقم 45 وهو غير متاح الآن', $card($conv->fresh()));
    }
}
