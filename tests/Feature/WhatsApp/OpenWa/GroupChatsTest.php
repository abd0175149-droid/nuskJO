<?php

namespace Tests\Feature\WhatsApp\OpenWa;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\OpenWa\UnknownNoticeCleanup;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * المجموعات (GroupChats، قرار المالك 2026-11-07): تُخزَّن بكاتب كل رسالة، ويرد عليها الموظف وحده، ولا يصلها بوت ولا
 * رسالة آلية. وإشعارات واتساب الداخلية (التشفير ورمز الأمان) لا تصير «رسالة لا يعرضها واتساب ويب».
 */
class GroupChatsTest extends TestCase
{
    use BuildsWhatsAppStaff, ConfiguresWhatsApp, RefreshDatabase;

    private const G = '120363012345678901@g.us';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        $this->fakeGateway();
    }

    private function groupMessage(string $body, string $author = '962791112233', string $name = 'أبو خالد'): array
    {
        return $this->incoming($author, $body, [
            'id' => 'false_'.self::G.'_'.strtoupper(bin2hex(random_bytes(6))).'_'.$author.'@c.us',
            'from' => self::G, 'chatId' => self::G, 'author' => $author.'@c.us', 'isGroup' => true, 'kind' => 'group',
            'contact' => ['pushName' => $name],
        ]);
    }

    public function test_a_group_message_is_stored_with_its_author_and_never_reaches_the_bot(): void
    {
        $this->postOpenWa('message.received', $this->groupMessage('السلام عليكم، متى الرحلة؟'))->assertOk();
        $this->postOpenWa('message.received', $this->groupMessage('بعد أسبوع', '962794445566', 'أم سامي'))->assertOk();

        $conv = WaConversation::sole();
        $this->assertTrue($conv->isGroup());
        $this->assertSame(self::G, $conv->jid);
        $this->assertNull($conv->phone);
        $this->assertFalse((bool) $conv->bot_enabled);
        $this->assertFalse((bool) $conv->needs_attention);
        $this->assertSame(2, (int) $conv->unread_count);
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
        // لا هاتف كاتب الرسالة محادثةً فردية
        $this->assertSame(0, WaConversation::where('phone', '962791112233')->count());

        $boss = $this->staffWith('boss', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $res = $this->actingAs($boss)->getJson("/api/whatsapp/conversations/{$conv->id}/messages")->assertOk();
        $this->assertSame(['أبو خالد', 'أم سامي'], collect($res->json('messages'))->pluck('sender')->all());
        $this->assertTrue($res->json('conversation.is_group'));
    }

    public function test_staff_reply_to_a_group_but_nothing_automatic_does(): void
    {
        $this->postOpenWa('message.received', $this->groupMessage('مرحبا'))->assertOk();
        $conv = WaConversation::sole();

        foreach (['system', 'bot'] as $source) {
            $res = WhatsAppChannel::sendText($conv, 'رسالة آلية', $source);
            $this->assertFalse($res['ok']);
            $this->assertSame('GROUP_STAFF_ONLY', $res['code']);
        }
        $this->assertSame([], $this->gatewaySends());

        $boss = $this->staffWith('boss', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $this->actingAs($boss)->postJson("/api/whatsapp/conversations/{$conv->id}/send", ['body' => 'أهلاً بالجميع'])->assertSuccessful();
        $sends = $this->gatewaySends();
        $this->assertCount(1, $sends);
        $this->assertSame(self::G, $sends[0]['body']['chatId']);
    }

    public function test_internal_notices_are_dropped_and_unknown_text_is_shown(): void
    {
        // إشعار التشفير عند الربط: بلا نص، والبوابة بلا rawType تقول unknown
        $this->postOpenWa('message.received', $this->incoming('962791110001', '', ['type' => 'unknown']))->assertOk();
        // مع الرقعة: النوع الأصلي
        $this->postOpenWa('message.received', $this->incoming('962791110002', '', ['type' => 'unknown', 'rawType' => 'notification_template']))->assertOk();
        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, WaConversation::count(), 'لا محادثة يفتحها إشعار داخلي');

        $this->postOpenWa('message.received', $this->incoming('962791110003', 'من أجواء تبليسي', ['type' => 'unknown']))->assertOk();
        $this->postOpenWa('message.received', $this->incoming('962791110004', '', ['type' => 'unknown', 'rawType' => 'ptv']))->assertOk();
        $this->assertSame(['text', 'video'], WaMessage::orderBy('id')->pluck('msg_type')->all());
    }

    public function test_the_cleanup_removes_stored_notices_and_fixes_their_conversations(): void
    {
        $ch = $this->testChannel();
        $only = WaConversation::forceCreate(['channel_id' => $ch->id, 'lid' => '111@lid', 'jid' => '111@lid', 'status' => 'open', 'unread_count' => 1,
            'needs_attention' => true, 'attention_reason' => 'unsupported', 'last_message_preview' => '⚠️ رسالة لا يعرضها واتساب ويب (unknown)']);
        $mixed = WaConversation::forceCreate(['channel_id' => $ch->id, 'phone' => '962791110009', 'jid' => '962791110009@c.us', 'status' => 'open', 'unread_count' => 2,
            'needs_attention' => true, 'attention_reason' => 'unsupported']);
        $row = fn (WaConversation $c, string $type, string $body, array $payload, string $at) => WaMessage::forceCreate([
            'conversation_id' => $c->id, 'channel_id' => $ch->id, 'wamid' => 'w'.bin2hex(random_bytes(4)), 'direction' => 'in', 'source' => 'customer',
            'msg_type' => $type, 'body' => $body, 'payload' => $payload, 'created_at' => $at, 'updated_at' => $at]);
        $row($only, 'unsupported', '⚠️ رسالة لا يعرضها واتساب ويب (unknown)', ['type' => 'unknown', 'body' => ''], '2026-11-07 07:43:25');
        $real = $row($mixed, 'text', 'بدي عرض عمرة', ['type' => 'text', 'body' => 'بدي عرض عمرة'], '2026-11-07 07:40:00');
        $row($mixed, 'unsupported', '⚠️ رسالة لا يعرضها واتساب ويب (unknown)', ['type' => 'unknown', 'body' => ''], '2026-11-07 07:43:25');
        $caption = $row($mixed, 'unsupported', '⚠️ رسالة لا يعرضها واتساب ويب (unknown)', ['type' => 'unknown', 'body' => 'من أجواء تبليسي'], '2026-11-07 07:30:00');

        $out = UnknownNoticeCleanup::run();

        $this->assertSame(['deleted' => 2, 'converted' => 1, 'conversations_removed' => 1, 'conversations_fixed' => 1], $out);
        $this->assertNull(WaConversation::find($only->id));
        $mixed->refresh();
        $this->assertSame([1, false, null, 'بدي عرض عمرة'], [(int) $mixed->unread_count, (bool) $mixed->needs_attention, $mixed->attention_reason, $mixed->last_message_preview]);
        $this->assertSame(['text', 'من أجواء تبليسي'], [$caption->refresh()->msg_type, $caption->body]);
        $this->assertNotNull($real->fresh());
        $this->assertSame(['deleted' => 0, 'converted' => 0, 'conversations_removed' => 0, 'conversations_fixed' => 0], UnknownNoticeCleanup::run(), 'آمن للإعادة');
    }
}
