<?php

namespace Tests\Feature\WhatsApp;

use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaChoiceSet;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\ChoiceBuilder;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\PatchesWhatsAppSettings;
use Tests\TestCase;

/**
 * نموذج طلب التسعير (E36، العلم quote_flow): Flow ثابت بوضع navigate بلا نقطة نهاية، برمز يولّده الخادم ومربوط بالمحادثة.
 * ردّه يصير طلب تسعير واحداً بحقول منقّاة عبر المصنع نفسه لأداة request_quote، مع تحويل لموظف وتأكيد نظامي. رمز مجهول أو
 * مستهلك أو من محادثة أخرى لا ينشئ شيئاً، والمحادثة «تحتاج تدخّلاً».
 */
class QuoteFlowTest extends TestCase
{
    use ConfiguresWhatsApp;
    use PatchesWhatsAppSettings;
    use RefreshDatabase;

    private WaConversation $conv;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel(['tools_config' => ['send_quote_form' => true], 'wa_quote_flow_id' => '1234567890123']);
        config(['features.env.quote_flow' => true, 'features.env.quote_dedupe' => true]);
        $this->conv = WaConversation::factory()->windowOpen()->create(['phone' => '16505551234', 'wa_phone' => '16505551234']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'أريد سعر تذكرة إلى جدة']);
        $this->fakeGraph();
    }

    private function sends(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), '/messages'))->values()->all();
    }

    /** نموذج أُرسل لهذه المحادثة (كما يحفظه الإرسال) */
    private function form(?WaConversation $conv = null): WaChoiceSet
    {
        $set = ChoiceBuilder::newSet($conv ?? $this->conv, 'quote_flow', [], Str::random(32));
        $set->save();
        $set->forceFill(['wamid' => 'wamid.FORM.'.$set->id])->save();

        return $set;
    }

    private function reply(?string $token, array $fields = []): WaMessage
    {
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/nfm_reply_quote.json')), true);
        $msg = &$payload['entry'][0]['changes'][0]['value']['messages'][0];
        $msg['id'] = 'wamid.NFM.'.(++$this->n);
        $msg['timestamp'] = (string) now()->getTimestamp();
        $response = json_decode($msg['interactive']['nfm_reply']['response_json'], true);
        $response = array_merge($response, [
            'flow_token' => $token, 'depart_date' => now()->addDays(30)->toDateString(), 'return_date' => now()->addDays(40)->toDateString(),
        ], $fields);
        if ($token === null) {
            unset($response['flow_token']);
        }
        $msg['interactive']['nfm_reply']['response_json'] = json_encode($response, JSON_UNESCAPED_UNICODE);
        WhatsAppInbox::handleWebhook($payload);

        return WaMessage::where('wamid', $msg['id'])->sole();
    }

    public function test_the_tool_sends_a_navigate_flow_with_a_server_token_and_no_data_exchange(): void
    {
        $this->fakeGemini(['call' => 'send_quote_form', 'args' => []], 'عبّئ هذا النموذج القصير ليصلك السعر، أو اكتب طلبك هنا إن شئت.');

        $this->assertTrue(BotEngine::reply($this->conv->fresh())['sent']);

        $this->assertCount(1, $this->sends());
        $req = $this->sends()[0];
        $params = $req['interactive']['action']['parameters'];
        $this->assertSame(['interactive', 'flow', 'flow'], [$req['type'], $req['interactive']['type'], $req['interactive']['action']['name']]);
        $this->assertSame('3', $params['flow_message_version']);
        $this->assertSame('navigate', $params['flow_action']);
        $this->assertSame(['screen' => 'QUOTE'], $params['flow_action_payload']);
        $this->assertSame(['1234567890123', 'تعبئة طلب التسعير', 'published'], [$params['flow_id'], $params['flow_cta'], $params['mode']]);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $params['flow_token']);
        $this->assertArrayNotHasKey('flow_name', $params);
        $this->assertStringNotContainsString('data_exchange', json_encode($req->data()));
        $this->assertStringStartsWith('عبّئ هذا النموذج القصير', $req['interactive']['body']['text']);

        $set = WaChoiceSet::sole();
        $this->assertSame(['quote_flow', $params['flow_token'], 'wamid.OUT.1'], [$set->kind, $set->nonce, $set->wamid]);
    }

    public function test_the_form_is_announced_only_with_the_flag_a_flow_id_a_long_window_and_no_recent_request(): void
    {
        $declared = fn () => array_column(BotTools::declarations(WaBotSetting::current(), $this->conv->fresh()), 'name');
        $this->assertContains('send_quote_form', $declared());

        config(['features.env.quote_flow' => false]);
        $this->assertNotContains('send_quote_form', $declared());
        config(['features.env.quote_flow' => true]);

        WaBotSetting::current()->forceFill(['wa_quote_flow_id' => null])->save();
        $this->assertNotContains('send_quote_form', $declared());
        WaBotSetting::current()->forceFill(['wa_quote_flow_id' => '1234567890123'])->save();

        $this->conv->forceFill(['last_inbound_at' => now()->subDay()->addMinutes(25)])->save();
        $this->assertNotContains('send_quote_form', $declared(), 'أقل من 30 دقيقة من النافذة');
        $this->conv->forceFill(['last_inbound_at' => now()])->save();

        QuoteRequest::create(['request_number' => 'Q-1', 'conversation_id' => $this->conv->id, 'phone' => '16505551234', 'type' => 'flight', 'status' => 'new', 'pax_adults' => 1]);
        $this->assertNotContains('send_quote_form', $declared(), 'طلب خلال 30 دقيقة');
    }

    public function test_a_valid_reply_creates_one_sanitized_request_hands_off_and_confirms(): void
    {
        $set = $this->form();

        $in = $this->reply($set->nonce);

        $qr = QuoteRequest::sole();
        $this->assertSame(['flight', 'عمّان', 'جدة', 2, 1, 0], [$qr->type, $qr->route_from, $qr->route_to, $qr->pax_adults, $qr->pax_children, $qr->pax_infants]);
        $this->assertSame(now()->addDays(30)->toDateString(), $qr->depart_date->toDateString());
        $this->assertStringContainsString('📝 من نموذج واتساب', $qr->details);
        $conv = $this->conv->fresh();
        $this->assertFalse($conv->bot_enabled);
        $this->assertTrue($conv->needs_attention);
        $this->assertSame((int) $in->id, (int) $conv->bot_answered_in_id, 'لا يرد البوت عليه');

        $this->assertSame("وصل طلبك رقم {$qr->request_number}، وسيعود إليك موظف بالسعر النهائي وتأكيد التوفّر.", $this->sends()[0]['text']['body']);
        $this->assertSame('system', WaMessage::where('direction', 'out')->sole()->source);
        $this->assertNotNull($set->fresh()->consumed_at);

        $this->assertStringStartsWith('📝 نموذج طلب تسعير: طيران — عمّان ← جدة', $in->body);
        $this->assertSame($qr->id, $in->payload['_flow']['quote_request_id']);
        $this->assertArrayNotHasKey('response_json', $in->payload['interactive']['nfm_reply']);
        $this->assertStringNotContainsString($set->nonce, json_encode($in->getAttributes(), JSON_UNESCAPED_UNICODE));
    }

    public function test_an_unknown_used_or_foreign_token_creates_nothing_and_asks_for_staff(): void
    {
        $other = WaConversation::factory()->windowOpen()->create();
        $foreign = $this->form($other);
        $expired = $this->form();
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->reply(Str::random(32));
        $this->reply(null);
        $this->reply($foreign->nonce);
        $this->reply($expired->nonce);

        $this->assertSame(0, QuoteRequest::count());
        $this->assertSame([], $this->sends());
        $conv = $this->conv->fresh();
        $this->assertTrue($conv->needs_attention);
        $this->assertSame('flow_invalid', $conv->attention_reason);
        $this->assertNull($foreign->fresh()->consumed_at);
    }

    public function test_unknown_fields_are_dropped_and_identity_like_numbers_are_scrubbed(): void
    {
        $set = $this->form();

        $in = $this->reply($set->nonce, [
            'passport_number' => 'N1234567', 'national_id' => '9981234567',
            'details' => "رقم جوازي N1234567 وهاتفي 079 123 4567\nنسافر في الصباح 2026-12-01",
            'adults' => '45', 'children' => 'كثير', 'type' => 'spaceship', 'route_to' => str_repeat('ج', 100),
        ]);

        $qr = QuoteRequest::sole();
        $stored = json_encode([$qr->getAttributes(), $in->getAttributes()], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('N1234567', $stored);
        $this->assertStringNotContainsString('9981234567', $stored);
        $this->assertStringNotContainsString('123 4567', $stored);
        $this->assertStringContainsString('[حُذف رقم]', $qr->details);
        $this->assertStringContainsString('2026-12-01', $qr->details, 'التاريخ ليس رقماً محذوفاً');
        $this->assertSame(['other', 1, 0, 60], [$qr->type, $qr->pax_adults, $qr->pax_children, mb_strlen($qr->route_to)]);
        $this->assertTrue($in->payload['_flow']['scrubbed']);
    }

    public function test_a_second_reply_does_not_create_a_second_request(): void
    {
        $first = $this->form();
        $this->reply($first->nonce);
        $this->reply($first->nonce);

        $second = $this->form();
        $this->reply($second->nonce);

        $this->assertSame(1, QuoteRequest::count());
        $this->assertCount(1, $this->sends(), 'تأكيد واحد');
    }

    public function test_dates_outside_today_to_two_years_are_dropped(): void
    {
        $set = $this->form();

        $this->reply($set->nonce, ['depart_date' => now()->subDay()->toDateString(), 'return_date' => now()->addYears(3)->toDateString()]);

        $qr = QuoteRequest::sole();
        $this->assertNull($qr->depart_date);
        $this->assertNull($qr->return_date);
    }

    public function test_the_flow_settings_are_validated(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $this->patchSettings($owner, ['wa_quote_flow_id' => 'abc'])->assertUnprocessable();
        $this->patchSettings($owner, ['wa_quote_flow_mode' => 'live'])->assertUnprocessable();
        $this->patchSettings($owner, ['wa_quote_flow_id' => '987654321', 'wa_quote_flow_mode' => 'draft'])->assertOk();

        $s = WaBotSetting::current()->fresh();
        $this->assertSame(['987654321', 'draft'], [$s->wa_quote_flow_id, $s->wa_quote_flow_mode]);
    }
}
