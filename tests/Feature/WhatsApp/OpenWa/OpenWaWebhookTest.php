<?php

namespace Tests\Feature\WhatsApp\OpenWa;

use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * webhook البوابة لكل رقم: التوقيع بسر الرقم، والرقم من uuid، والجلسة تطابق، ومنع التكرار، والتخزين على الرقم.
 */
class OpenWaWebhookTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        $this->fakeGateway();
    }

    public function test_signed_text_message_is_stored_on_the_channel(): void
    {
        $this->postOpenWa('message.received', $this->incoming('962791112233', 'السلام عليكم'))->assertOk();

        $conv = WaConversation::sole();
        $this->assertSame($this->testChannel()->id, (int) $conv->channel_id);
        $this->assertSame('962791112233@c.us', $conv->jid);
        $this->assertSame('962791112233', $conv->phone);
        $m = WaMessage::sole();
        $this->assertSame('السلام عليكم', $m->body);
        $this->assertSame((int) $conv->channel_id, (int) $m->channel_id);
        $this->assertSame('in', $m->direction);
    }

    public function test_wrong_or_missing_signature_is_rejected(): void
    {
        $this->postOpenWa('message.received', $this->incoming('962791112233'), secret: 'wrong-secret-wrong-secret')->assertForbidden();
        $raw = json_encode(['event' => 'message.received', 'sessionId' => $this->testChannel()->openwa_session_id, 'data' => $this->incoming('962791112233')]);
        $this->postOpenWaRaw($raw, $this->testChannel(), null)->assertForbidden();
        $this->assertSame(0, WaMessage::count());
    }

    public function test_unknown_channel_is_404(): void
    {
        $this->call('POST', '/api/openwa/webhook/00000000-0000-0000-0000-000000000000', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertNotFound();
    }

    public function test_payload_for_another_session_is_refused(): void
    {
        $ch = $this->testChannel();
        $raw = json_encode(['event' => 'message.received', 'sessionId' => 'someone-else', 'data' => $this->incoming('962791112233')]);
        $this->postOpenWaRaw($raw, $ch)->assertStatus(409);
        $this->assertSame(0, WaMessage::count());
    }

    public function test_same_delivery_twice_is_processed_once(): void
    {
        $msg = $this->incoming('962791112233', 'مرحبا');
        $this->postOpenWa('message.received', $msg, idempotency: 'k-1')->assertOk();
        $this->postOpenWa('message.received', $msg, idempotency: 'k-1')->assertOk();
        $this->postOpenWa('message.received', $msg, idempotency: 'k-2')->assertOk();
        $this->assertSame(1, WaMessage::count());
    }

    public function test_status_and_system_messages_are_dropped_and_groups_are_kept_apart(): void
    {
        $this->postOpenWa('message.received', $this->incoming('962791112233', 'x', ['chatId' => 'status@broadcast']))->assertOk();
        $this->postOpenWa('message.received', $this->incoming('962791112233', '', ['type' => 'e2e_notification']))->assertOk();
        $this->assertSame(0, WaMessage::count());

        // المجموعة تُخزَّن (GroupChats: عرض ورد موظف) ولا تصير محادثة فردية لكاتبها
        $this->postOpenWa('message.received', $this->incoming('962791112233', 'x', ['chatId' => '120363000123@g.us', 'from' => '120363000123@g.us',
            'author' => '962791112233@c.us', 'isGroup' => true]))->assertOk();
        $this->assertSame(1, WaMessage::count());
        $this->assertSame([true, null], [(bool) \App\Models\WaConversation::sole()->is_group, \App\Models\WaConversation::sole()->phone]);
    }

    public function test_lid_sender_with_phone_is_linked_by_phone_and_lid(): void
    {
        $this->postOpenWa('message.received', $this->incoming('962791112233', 'hi', [
            'from' => '123456789012345@lid', 'chatId' => '123456789012345@lid', 'senderPhone' => '962791112233',
        ]))->assertOk();
        $conv = WaConversation::sole();
        $this->assertSame('962791112233', $conv->phone);
        $this->assertSame('123456789012345@lid', $conv->lid);
        $this->assertSame('123456789012345@lid', $conv->jid);
    }

    public function test_ack_advances_status_of_our_message(): void
    {
        $this->postOpenWa('message.received', $this->incoming('962791112233', 'hi'))->assertOk();
        $conv = WaConversation::sole();
        $res = \App\Services\WhatsApp\WhatsAppChannel::sendText($conv, 'أهلاً بك', 'system');
        $this->assertTrue($res['ok']);
        $this->postOpenWa('message.ack', ['messageId' => $res['message_id'], 'status' => 'delivered', 'chatId' => $conv->jid])->assertOk();
        $this->assertSame('delivered', WaMessage::where('wamid', $res['message_id'])->value('status'));
        $this->postOpenWa('message.ack', ['messageId' => $res['message_id'], 'status' => 'read'])->assertOk();
        $this->assertSame('read', WaMessage::where('wamid', $res['message_id'])->value('status'));
    }

    public function test_phone_echo_is_stored_as_staff_phone_and_pauses_the_bot(): void
    {
        $this->postOpenWa('message.received', $this->incoming('962791112233', 'hi'))->assertOk();
        $this->postOpenWa('message.sent', [
            'id' => 'true_962791112233@c.us_ABC', 'from' => self::TEST_CHANNEL_PHONE.'@c.us', 'to' => '962791112233@c.us',
            'chatId' => '962791112233@c.us', 'body' => 'رد من الهاتف', 'type' => 'text', 'fromMe' => true, 'timestamp' => now()->getTimestamp(),
        ])->assertOk();
        $m = WaMessage::where('wamid', 'true_962791112233@c.us_ABC')->sole();
        $this->assertSame('staff_phone', $m->source);
        $this->assertTrue(WaConversation::sole()->bot_paused_until->isFuture());
    }

    public function test_echo_of_our_own_send_is_not_duplicated(): void
    {
        $this->postOpenWa('message.received', $this->incoming('962791112233', 'hi'))->assertOk();
        $res = \App\Services\WhatsApp\WhatsAppChannel::sendText(WaConversation::sole(), 'نص', 'system');
        $this->postOpenWa('message.sent', ['id' => $res['message_id'], 'chatId' => '962791112233@c.us', 'body' => 'نص', 'type' => 'text', 'fromMe' => true])->assertOk();
        $this->assertSame(1, WaMessage::where('direction', 'out')->count());
    }

    public function test_session_qr_and_authenticated_update_the_channel(): void
    {
        $ch = $this->testChannel();
        $ch->forceFill(['status' => 'initializing', 'phone' => null])->save();
        $this->postOpenWa('session.qr', ['sessionId' => $ch->openwa_session_id, 'qr' => 'data:image/png;base64,AAAA'])->assertOk();
        $this->assertSame('qr_ready', $ch->fresh()->status);
        $this->postOpenWa('session.authenticated', ['sessionId' => $ch->openwa_session_id, 'phone' => '962795550000', 'pushName' => 'نسك'])->assertOk();
        $fresh = $ch->fresh();
        $this->assertSame('ready', $fresh->status);
        $this->assertSame('962795550000', $fresh->phone);
    }

    public function test_restriction_pauses_the_channel(): void
    {
        $ch = $this->testChannel();
        $this->postOpenWa('session.restriction', ['sessionId' => $ch->openwa_session_id, 'type' => 'temporary'])->assertOk();
        $this->assertTrue($ch->fresh()->isPaused());
        $this->assertSame('restriction', $ch->fresh()->pause_reason);
    }
}
