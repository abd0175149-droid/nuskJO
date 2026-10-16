<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaContactCard;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\Outbound\OutboundMessage;
use App\Services\WhatsApp\Outbound\RichTypes;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * بطاقات الاتصال (E33، العلم wa_share_cards، C007): من wa_contact_cards المعتمدة وحدها (حتى 3)، وOutboundPolicy ترفض أي رقم
 * خارجها. والبطاقة الواردة بالاسم وحده في body وسياق النموذج، والرقم لمن يملك whatsapp.reply وحده.
 */
class ShareContactCardTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private WaConversation $conv;

    private WaContactCard $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.wa_share_cards' => true]);
        $this->conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'أعطني رقم المندوب']);
        $this->office = $this->card(['label' => 'المندوب في جدة', 'formatted_name' => 'مندوب نسك', 'org' => 'نسك الذهبية', 'job_title' => 'مندوب',
            'phone_e164' => '+16505553333', 'wa_id' => '16505553333', 'url' => 'https://www.nusuk.sa', 'bot_allowed' => true]);
    }

    private function card(array $attrs = []): WaContactCard
    {
        return WaContactCard::create($attrs + ['label' => 'بطاقة', 'formatted_name' => 'اسم', 'phone_e164' => '+16505553333',
            'consent_note' => 'وافق كتابياً عبر البريد', 'consented_at' => now()->subDay()]);
    }

    private function requests(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), '/messages'))->values()->all();
    }

    public function test_the_payload_carries_the_name_org_phone_and_wa_id(): void
    {
        config(['audit.extended' => true]);
        $this->fakeGraph();

        $res = WhatsAppChannel::sendContacts($this->conv, collect([$this->office]), 'bot', null, ['send_key' => 'reply:card:1']);

        $this->assertTrue($res['ok']);
        $this->assertSame([[
            'name' => ['formatted_name' => 'مندوب نسك', 'first_name' => 'مندوب نسك'],
            'org' => ['company' => 'نسك الذهبية', 'title' => 'مندوب'],
            'phones' => [['phone' => '+16505553333', 'type' => 'WORK', 'wa_id' => '16505553333']],
            'urls' => [['url' => 'https://www.nusuk.sa', 'type' => 'WORK']],
        ]], $this->requests()[0]['contacts']);
        $row = WaMessage::where('direction', 'out')->sole();
        $this->assertSame(['contacts', '👤 بطاقة: مندوب نسك', [$this->office->id]], [$row->msg_type, $row->body, data_get($row->payload, '_meta.card_ids')]);
        $this->assertSame([$this->office->id], AuditLog::where('action', 'wa.share_card')->sole()->changes['card_ids']);
    }

    public function test_the_policy_refuses_a_number_that_is_not_on_an_approved_card(): void
    {
        $this->fakeGraph();
        RichTypes::register();
        $forged = $this->office->toPayload();
        $forged['phones'][0]['phone'] = '+15550783881';
        $res = WhatsAppChannel::send($this->conv, OutboundMessage::custom('contacts', ['contacts' => [$forged]]), 'bot', null,
            ['send_key' => 'reply:card:2', 'meta' => ['card_ids' => [$this->office->id]]]);
        $forgedWaId = $this->office->toPayload();
        $forgedWaId['phones'][0]['wa_id'] = '15550783881';
        $res2 = WhatsAppChannel::send($this->conv, OutboundMessage::custom('contacts', ['contacts' => [$forgedWaId]]), 'bot', null,
            ['send_key' => 'reply:card:3', 'meta' => ['card_ids' => [$this->office->id]]]);

        $this->assertSame([[false, 'POLICY_CARD_MISMATCH'], [false, 'POLICY_CARD_MISMATCH']], [[$res['ok'], $res['code']], [$res2['ok'], $res2['code']]]);
        Http::assertNothingSent();
    }

    public function test_the_staff_route_accepts_up_to_three_active_cards(): void
    {
        $this->fakeGraph();
        $staff = $this->staffWith('desk', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $post = fn (array $ids) => $this->actingAs($staff)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/whatsapp/conversations/{$this->conv->id}/send-contact", ['card_ids' => $ids]);
        $more = [$this->card()->id, $this->card()->id, $this->card()->id];
        $inactive = $this->card(['is_active' => false]);

        $post([$this->office->id, ...$more])->assertStatus(422)->assertJsonValidationErrors('card_ids');
        $post([$inactive->id])->assertStatus(422)->assertJson(['code' => 'CARD_INACTIVE']);
        $post([$this->office->id, $more[0]])->assertOk()->assertJson(['ok' => true]);

        $this->assertCount(1, $this->requests());
        $this->assertCount(2, $this->requests()[0]['contacts']);
    }

    public function test_an_inbound_card_keeps_its_number_out_of_the_body_and_the_model_context(): void
    {
        $this->travelTo(Carbon::createFromTimestamp(1760425540, config('app.timezone')));
        $conv = WaConversation::factory()->create(['phone' => '16505551234', 'wa_phone' => '16505551234', 'last_inbound_at' => now()->subHour()]);

        $this->postMetaWebhook('contacts.json')->assertOk();

        $row = WaMessage::where('msg_type', 'contacts')->sole();
        $this->assertSame('👤 بطاقة جهة اتصال: Sheena Nelson', $row->body);
        $this->assertDoesNotMatchRegularExpression('/\d{4}/', (string) $conv->fresh()->last_message_preview);
        $this->assertSame('16505553333', data_get($row->payload, 'contacts.0.phones.0.wa_id'), 'الرقم في payload وحده');

        $messages = (new \ReflectionMethod(BotEngine::class, 'buildMessages'))->invoke(null, $conv->fresh(), WaBotSetting::current(), null);
        $this->assertDoesNotMatchRegularExpression('/555|3333/', implode("\n", array_column($messages, 'text')));
    }

    public function test_the_thread_returns_card_numbers_to_reply_holders_only(): void
    {
        $this->travelTo(Carbon::createFromTimestamp(1760425540, config('app.timezone')));
        $conv = WaConversation::factory()->create(['phone' => '16505551234', 'wa_phone' => '16505551234', 'last_inbound_at' => now()->subHour()]);
        $this->postMetaWebhook('contacts.json')->assertOk();
        $replier = $this->staffWith('desk', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $viewer = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all'], 'viewer')->create();
        $card = fn (User $u) => collect($this->actingAs($u)->getJson("/api/whatsapp/conversations/{$conv->id}/messages")->assertOk()->json('messages'))
            ->firstWhere('type', 'contacts')['contact_cards'];

        $this->assertSame([['name' => 'Sheena Nelson', 'phones' => ['+1 650-555-3333']]], $card($replier));
        $this->assertSame([['name' => 'Sheena Nelson']], $card($viewer));
    }

    public function test_the_conversation_export_shows_cards_by_name_only(): void
    {
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'contacts', 'body' => '👤 جهة اتصال: أخي — +1 650-555-3333']);
        $h = fopen('php://memory', 'w+');

        (new \App\Services\WhatsApp\ConversationExporter)->stream($this->conv, 'txt', null, null, $h);
        rewind($h);
        $text = (string) stream_get_contents($h);

        $this->assertStringContainsString('👤 جهة اتصال: أخي', $text);
        $this->assertStringNotContainsString('555', $text);
    }

    public function test_the_optional_bot_tool_takes_only_a_listed_key(): void
    {
        $this->fakeGraph();
        $hidden = $this->card(['bot_allowed' => false]);

        $decl = collect(BotTools::declarations(new WaBotSetting(['tools_config' => ['send_contact_card' => true]]), $this->conv))->firstWhere('name', 'send_contact_card');
        $this->assertSame(['card_'.$this->office->id], $decl['input_schema']['properties']['card_key']['enum']);

        $refused = BotTools::execute('send_contact_card', ['card_key' => 'card_'.$hidden->id], $this->conv);
        $this->assertFalse($refused['ok']);
        Http::assertNothingSent();

        $sent = BotTools::execute('send_contact_card', ['card_key' => 'card_'.$this->office->id], $this->conv);
        $this->assertTrue($sent['sent']);
        $this->assertSame('+16505553333', $this->requests()[0]['contacts'][0]['phones'][0]['phone']);
    }
}
