<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Client;
use App\Models\Offer;
use App\Models\QuoteRequest;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\IdentityMode;
use App\Services\WhatsApp\ReplyState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * حواجز البوت مع الهوية (E11.7): لا رد على محادثة مدموجة ولا على تحديث هوية، والاقتباس من المحادثة نفسها،
 * وأرقام بطاقات الاتصال لا تصل للنموذج، والمحادثة بلا رقم لا ترى بيانات مالية ويصلها زر مشاركة الرقم مع الطلب.
 */
class BotIdentityGuardsTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const BSUID = 'US.13491208655302741918';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
    }

    /** نص كل ما أُرسل للنموذج */
    private function llmPrompts(): string
    {
        return Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'generativelanguage'))
            ->map(fn ($pair) => json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode("\n");
    }

    public function test_a_merged_conversation_is_never_answered(): void
    {
        $this->fakeGraph();
        $conv = WaConversation::factory()->windowOpen()->create(['status' => 'merged']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id]);

        $this->assertSame('merged', BotEngine::reply($conv)['reason']);
        Http::assertNothingSent();
    }

    public function test_an_identity_update_is_not_a_pending_message(): void
    {
        $this->fakeGraph();
        $conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->create(['conversation_id' => $conv->id, 'direction' => 'in', 'source' => 'system', 'msg_type' => 'system', 'body' => '🔄 غيّر العميل رقمه']);

        $this->assertFalse(ReplyState::hasPending($conv));
        $this->artisan('whatsapp:reliability', ['flag' => 'watermark', 'state' => 'off']);
        $this->assertSame('لا وارد بعد آخر رد', BotEngine::reply($conv->fresh())['reason']);
        Http::assertNothingSent();
    }

    public function test_quotes_come_from_the_same_conversation_and_shared_numbers_are_masked(): void
    {
        $this->fakeGraph();
        $this->fakeGemini('أهلاً');
        $conv = WaConversation::factory()->windowOpen()->create();
        $other = WaConversation::factory()->create();
        WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'wamid' => 'wamid.MINE', 'body' => 'عرض عمرة رمضان بسعر خاص']);
        WaMessage::factory()->outbound()->create(['conversation_id' => $other->id, 'wamid' => 'wamid.THEIRS', 'body' => 'سر محادثة أخرى']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'msg_type' => 'contacts', 'body' => '👤 جهة اتصال: أخي — +1 650-555-3333']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'reply_to_wamid' => 'wamid.MINE', 'body' => 'هل ما زال متاحاً؟']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'reply_to_wamid' => 'wamid.THEIRS', 'body' => 'وهذا؟']);

        BotEngine::reply($conv);

        $prompt = $this->llmPrompts();
        $this->assertStringContainsString('[ردّاً على: «عرض عمرة رمضان بسعر خاص»] هل ما زال متاحاً؟', $prompt);
        $this->assertStringNotContainsString('سر محادثة أخرى', $prompt);
        $this->assertStringNotContainsString('650-555-3333', $prompt);
        $this->assertStringContainsString('[رقم محفوظ للموظف]', $prompt);
    }

    public function test_the_card_of_a_phoneless_customer_has_no_identifiers_and_no_money(): void
    {
        Setting::set('wa_identity_mode', IdentityMode::FULL);
        $this->fakeGraph();
        $this->fakeGemini('أهلاً');
        $conv = WaConversation::factory()->windowOpen()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => self::BSUID, 'wa_username' => 'secret.handle',
            'client_id' => Client::factory()->create()->id]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'كم رصيدي؟']);

        BotEngine::reply($conv);

        $prompt = $this->llmPrompts();
        $this->assertStringNotContainsString(self::BSUID, $prompt);
        $this->assertStringNotContainsString('secret.handle', $prompt);
        $this->assertStringNotContainsString('رصيد ذمّته', $prompt);
        $this->assertStringContainsString('لا تكشف أي بيانات مالية', $prompt);
    }

    public function test_a_quote_from_a_phoneless_customer_sends_the_share_number_button_in_full_mode(): void
    {
        Setting::set('wa_identity_mode', IdentityMode::FULL);
        User::factory()->admin()->create();
        $this->fakeGraph();
        $conv = WaConversation::factory()->windowOpen()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => self::BSUID, 'display_name' => null, 'wa_profile_name' => 'لقب']);

        $out = BotTools::execute('request_quote', ['type' => 'flight', 'route_from' => 'عمّان', 'route_to' => 'جدة', 'adults' => 1], $conv);

        Http::assertSent(fn (Request $r) => data_get($r->data(), 'interactive.type') === 'request_contact_info' && $r['recipient'] === self::BSUID);
        $this->assertStringContainsString('زرٌّ لمشاركة رقم هاتفه', $out['note']);
        $this->assertSame(['', 'لقب'], [QuoteRequest::sole()->phone, QuoteRequest::sole()->customer_name]);
    }

    public function test_in_staff_mode_the_quote_is_kept_without_the_button(): void
    {
        Setting::set('wa_identity_mode', IdentityMode::STAFF);
        User::factory()->admin()->create();
        $this->fakeGraph();
        $conv = WaConversation::factory()->windowOpen()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => self::BSUID]);

        $out = BotTools::execute('request_quote', ['type' => 'flight', 'adults' => 1], $conv);

        Http::assertNothingSent();
        $this->assertStringNotContainsString('زرٌّ', $out['note']);
        $this->assertSame(1, QuoteRequest::count());
    }

    public function test_the_linked_client_name_wins_over_the_whatsapp_nickname(): void
    {
        $this->fakeGraph();
        User::factory()->admin()->create();
        $client = Client::factory()->create(['name' => 'الاسم المسجّل']);
        $conv = WaConversation::factory()->windowOpen()->create(['client_id' => $client->id, 'display_name' => null, 'wa_profile_name' => 'لقب']);

        BotTools::execute('request_quote', ['type' => 'flight', 'adults' => 1], $conv);

        $this->assertSame('الاسم المسجّل', QuoteRequest::sole()->customer_name);
    }

    public function test_the_invoice_prefill_prefers_the_client_name_and_a_number_learned_later(): void
    {
        $client = Client::factory()->create(['name' => 'الاسم المسجّل']);
        $conv = WaConversation::factory()->create(['phone' => '16505551234', 'wa_phone' => '16505551234']);
        $offer = Offer::factory()->create();
        $qr = QuoteRequest::create(['request_number' => 'Q-1', 'conversation_id' => $conv->id, 'client_id' => $client->id, 'offer_id' => $offer->id,
            'phone' => '', 'customer_name' => 'لقب', 'type' => 'package', 'pax_adults' => 1, 'status' => 'new']);
        $staff = User::factory()->admin()->create();

        $this->actingAs($staff)->get('/invoices?quote='.$qr->id)->assertInertia(fn ($page) => $page
            ->where('prefill.client_name', 'الاسم المسجّل')->where('prefill.client_phone', '+16505551234'));
    }
}
