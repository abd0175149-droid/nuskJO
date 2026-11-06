<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الصادر (E11.6، على OpenWA منذ 2026-11-06): كل رسالة تحفظ الرقم الذي أُرسلت منه (channel_id) ورمز الخطأ الداخلي عند الفشل،
 * و«طلب الرقم» نص يطلب كتابة الرقم (لا زر في واتساب ويب) يخرج إلى jid المحادثة ويمر بالأقفال نفسها.
 */
class ChannelRecipientTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const LID = '204512376140012@lid';

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all', 'whatsapp.reply'], 'desk')->create();
    }

    private function phoneless(array $attrs = []): WaConversation
    {
        return WaConversation::factory()->create($attrs + ['phone' => null, 'wa_phone' => null, 'jid' => self::LID, 'lid' => self::LID]);
    }

    private function requestContact(WaConversation $conv, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->staff)->postJson("/api/whatsapp/conversations/{$conv->id}/request-contact");
    }

    public function test_outbound_messages_keep_our_number_and_the_internal_error_code(): void
    {
        $this->fakeGateway(['*/messages/send-text' => Http::sequence()
            ->push(['messageId' => 'wamid.OUT.1'], 201)
            ->push(['statusCode' => 400, 'message' => 'invalid payload'], 400)]);
        $conv = WaConversation::factory()->create();

        $this->assertTrue(WhatsAppChannel::sendText($conv, 'أهلاً')['ok']);
        $this->assertFalse(WhatsAppChannel::sendText($conv, 'ثانية')['ok']);

        $channel = $this->testChannel()->id;
        $sent = WaMessage::where('status', 'sent')->sole();
        $failed = WaMessage::where('status', 'failed')->sole();
        $this->assertSame([$channel, null], [$sent->channel_id, $sent->error_code]);
        $this->assertSame([$channel, 'GW_REJECTED'], [$failed->channel_id, $failed->error_code]);
    }

    public function test_an_accepted_send_without_a_message_id_is_stored_as_unconfirmed(): void
    {
        $this->fakeGateway(['*/messages/send-text' => Http::response(['success' => true], 201)]);

        $result = WhatsAppChannel::sendText(WaConversation::factory()->create(), 'أهلاً');

        $this->assertSame([false, true, null], [$result['ok'], $result['unknown'], $result['message_id']]);
        $this->assertSame(['unknown', null], [WaMessage::sole()->status, WaMessage::sole()->wamid]);
    }

    public function test_a_staff_message_lost_to_a_timeout_stays_visible_as_unconfirmed(): void
    {
        $this->fakeGateway(['*/messages/send-text' => Http::failedConnection('cURL error 28: Operation timed out after 90001 milliseconds')]);
        $conv = WaConversation::factory()->create();

        $staff = WhatsAppChannel::sendText($conv, 'موعدك غداً', 'staff', $this->staff->id);

        $this->assertSame([false, 'GW_TIMEOUT'], [$staff['ok'], $staff['code']]);
        $row = WaMessage::sole();
        $this->assertSame(['unknown', 'staff', 'موعدك غداً', 'GW_TIMEOUT', null], [$row->status, $row->source, $row->body, $row->error_code, $row->wamid]);
    }

    public function test_the_contact_request_asks_to_type_the_number_and_goes_to_the_conversation_jid(): void
    {
        $this->fakeGateway();
        $conv = $this->phoneless();

        $this->requestContact($conv)->assertOk()->assertJsonPath('ok', true);

        $send = $this->gatewaySends()[0];
        $this->assertSame(['send-text', self::LID], [$send['path'], $send['body']['chatId']]);
        $this->assertStringContainsString('اكتب رقم هاتفك', $send['body']['text']);
        $this->assertStringNotContainsString('الزر', $send['body']['text'], 'لا أزرار في واتساب ويب');
        $m = WaMessage::sole();
        $this->assertSame(['staff', WhatsAppChannel::CONTACT_REQUEST_LABEL, $this->staff->id], [$m->source, $m->body, $m->staff_id]);
    }

    public function test_it_is_refused_when_the_number_is_known_or_there_is_no_target(): void
    {
        $this->fakeGateway();
        $this->requestContact(WaConversation::factory()->create())->assertStatus(422)->assertJsonPath('code', 'HAS_PHONE');

        $this->requestContact($this->phoneless(['jid' => null, 'lid' => null]))->assertStatus(422)->assertJsonPath('code', WhatsAppChannel::ERR_NO_RECIPIENT);

        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
    }

    public function test_it_needs_the_reply_permission(): void
    {
        $this->fakeGateway();
        $viewer = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all'], 'viewer')->create();

        $this->requestContact($this->phoneless(), $viewer)->assertForbidden();
        Http::assertNothingSent();
    }
}
