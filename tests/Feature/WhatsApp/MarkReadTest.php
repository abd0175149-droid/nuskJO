<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\ReadReceipts;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * إشعار القراءة (E35، منذ 2026-11-06 عبر البوابة): POST /chats/read على محادثة العميل، والعلامة على أحدث وارد،
 * خارج dispatch (لا صف ولا مفتاح)، بأقفال الرقم والإيقاف والاتصال وحدها، وفشله سطر وعدّاد لا يمس حالة الرقم.
 */
class MarkReadTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    private WaMessage $in;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.read_receipts' => true]);
        $this->configureChannel(['send_read_receipts' => 'on_reply']);
        $this->conv = WaConversation::factory()->windowOpen()->create();
        $this->in = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.IN.1']);
    }

    private function readCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/chats/read'))->count();
    }

    public function test_the_request_is_a_read_on_the_chat_and_the_mark_is_the_newest_inbound(): void
    {
        $this->fakeGateway();
        $older = $this->in;
        $newest = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.IN.2']);

        $res = WhatsAppChannel::markRead($this->conv);

        $this->assertSame(['ok' => true, 'code' => null], $res);
        $chat = WhatsAppChannel::chatId($this->conv);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://nuskjo-openwa:2785/api/sessions/sess-1/chats/read'
            && $r->data() === ['chatId' => $chat] && $r->header('X-API-Key') === ['key-1']);
        $this->assertSame($newest->id, $this->conv->fresh()->read_receipt_upto_id);
        $this->assertGreaterThan($older->id, $this->conv->fresh()->read_receipt_upto_id);
    }

    public function test_typing_follows_the_read_on_the_same_chat(): void
    {
        $this->fakeGateway();

        WhatsAppChannel::markRead($this->conv, typing: true);

        $paths = collect(Http::recorded())->map(fn ($p) => preg_replace('#^.*/sess-1#', '', $p[0]->url()))->values()->all();
        $this->assertSame(['/chats/read', '/chats/typing'], $paths);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/chats/typing')
            && $r->data() === ['chatId' => WhatsAppChannel::chatId($this->conv), 'state' => 'typing']);
    }

    public function test_it_is_not_a_message_no_row_and_no_send_key(): void
    {
        $this->fakeGateway();
        $before = WaMessage::count();

        WhatsAppChannel::markRead($this->conv, typing: true);

        $this->assertSame($before, WaMessage::count());
        $this->assertSame(0, WaMessage::whereNotNull('send_key')->count());
        $this->assertNull($this->conv->fresh()->last_outbound_at);
    }

    public function test_it_needs_an_inbound_within_30_days(): void
    {
        $this->fakeGateway();
        $this->conv->update(['last_inbound_at' => now()->subDays(3)]);
        $this->in->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->assertTrue(WhatsAppChannel::markRead($this->conv)['ok']);

        $this->in->forceFill(['created_at' => now()->subDays(31)])->save();
        $this->conv->update(['read_receipt_upto_id' => null]);
        $this->assertSame(['ok' => false, 'code' => 'NO_TARGET'], WhatsAppChannel::markRead($this->conv));
        $this->assertSame(1, $this->readCalls());
    }

    public function test_reactions_and_system_messages_are_never_the_target(): void
    {
        $this->fakeGateway();
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.REACT', 'msg_type' => 'reaction', 'body' => '👍']);
        WaMessage::factory()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.SYS', 'direction' => 'in', 'source' => 'system', 'msg_type' => 'system']);

        WhatsAppChannel::markRead($this->conv);

        $this->assertSame($this->in->id, $this->conv->fresh()->read_receipt_upto_id);
        $this->assertSame(1, $this->readCalls());
    }

    public function test_upto_limits_the_mark_to_what_existed_then(): void
    {
        $this->fakeGateway();
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.IN.LATER']);

        WhatsAppChannel::markRead($this->conv, false, $this->in->id);

        $this->assertSame(1, $this->readCalls());
        $this->assertSame($this->in->id, $this->conv->fresh()->read_receipt_upto_id);
    }

    public function test_a_second_receipt_without_typing_on_the_same_message_makes_no_request(): void
    {
        $this->fakeGateway();

        $this->assertTrue(WhatsAppChannel::markRead($this->conv)['ok']);
        $this->assertSame(['ok' => false, 'code' => 'ALREADY'], WhatsAppChannel::markRead($this->conv));
        // المؤشر يُجدَّد على الرسالة نفسها
        $this->assertTrue(WhatsAppChannel::markRead($this->conv, typing: true)['ok']);

        $this->assertSame(2, $this->readCalls());
    }

    public function test_the_mark_only_moves_forward(): void
    {
        $this->fakeGateway();
        $newest = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.IN.2']);
        $this->conv->update(['read_receipt_upto_id' => $newest->id]);

        // مؤشر على رسالة أقدم (upto): ينجح ولا يعيد العلامة إلى الوراء
        WhatsAppChannel::markRead($this->conv, true, $this->in->id);

        $this->assertSame($newest->id, $this->conv->fresh()->read_receipt_upto_id);
    }

    public function test_nothing_is_sent_when_the_feature_is_off_or_a_lock_holds(): void
    {
        $this->fakeGateway();

        config(['features.env.read_receipts' => false]);
        $this->assertSame('DISABLED', WhatsAppChannel::markRead($this->conv)['code']);

        config(['features.env.read_receipts' => true]);
        WaBotSetting::current()->forceFill(['send_read_receipts' => 'off'])->save();
        $this->assertSame('DISABLED', WhatsAppChannel::markRead($this->conv)['code']);

        WaBotSetting::current()->forceFill(['send_read_receipts' => 'on_reply', 'wa_suspended' => true])->save();
        $this->assertSame(WhatsAppChannel::ERR_SUSPENDED, WhatsAppChannel::markRead($this->conv)['code']);

        config(['services.whatsapp.suspended' => true]);
        WaBotSetting::current()->forceFill(['wa_suspended' => false])->save();
        $this->assertSame(WhatsAppChannel::ERR_SUSPENDED, WhatsAppChannel::markRead($this->conv)['code']);
        config(['services.whatsapp.suspended' => false]);

        $ch = $this->testChannel();
        $ch->update(['paused_until' => now()->addHour()]);
        $this->assertSame(WhatsAppChannel::ERR_PAUSED, WhatsAppChannel::markRead($this->conv->fresh())['code']);

        $ch->update(['paused_until' => null, 'status' => 'disconnected']);
        $this->assertSame(WhatsAppChannel::ERR_NOT_READY, WhatsAppChannel::markRead($this->conv->fresh())['code']);

        $ch->update(['status' => 'ready', 'is_active' => false]);
        $this->assertSame(WhatsAppChannel::ERR_NO_CHANNEL, WhatsAppChannel::markRead($this->conv->fresh())['code']);

        $ch->update(['is_active' => true]);
        $this->conv->update(['status' => 'merged']);
        $this->assertSame('MERGED', WhatsAppChannel::markRead($this->conv->fresh())['code']);

        Http::assertNothingSent();
        $this->assertNull($this->conv->fresh()->read_receipt_upto_id);
    }

    public function test_a_rejected_receipt_is_logged_and_counted_without_touching_the_channel(): void
    {
        $this->captureLogs();
        $this->fakeGateway(['nuskjo-openwa:2785/api/sessions/*/chats/read' => Http::response(['statusCode' => 400, 'message' => 'Chat not found'], 400)]);
        $before = $this->testChannel()->only(['status', 'paused_until', 'probe_failures']);

        $res = WhatsAppChannel::markRead($this->conv);

        $this->assertSame(['ok' => false, 'code' => 'GW_REJECTED'], $res);
        $this->assertEquals($before, $this->testChannel()->fresh()->only(['status', 'paused_until', 'probe_failures']));
        $this->assertFalse((bool) WaBotSetting::current()->fresh()->wa_suspended);
        $this->assertNull($this->conv->fresh()->read_receipt_upto_id);
        $this->assertSame(1, ReadReceipts::failuresToday());
        $this->assertSame('GW_REJECTED', ReadReceipts::lastFailureCode());
        $this->assertLogged('info', 'wa.read_receipt_failed', ['conv_id' => $this->conv->id, 'code' => 'GW_REJECTED', 'meta_code' => null, 'fbtrace_id' => null, 'http' => 400]);
        // لا نص ولا رقم في السطر
        $line = collect($this->logged)->firstWhere('message', 'wa.read_receipt_failed');
        $this->assertSame([], array_diff(array_keys($line['context']), ['conv_id', 'code', 'meta_code', 'fbtrace_id', 'http']));
    }

    public function test_a_timeout_is_a_quiet_failure_too(): void
    {
        $this->fakeGateway(['nuskjo-openwa:2785/api/sessions/*/chats/read' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds')]);

        $res = WhatsAppChannel::markRead($this->conv, typing: true);

        $this->assertSame(['ok' => false, 'code' => 'GW_TIMEOUT'], $res);
        $this->assertSame(1, ReadReceipts::failuresToday());
        $this->assertSame(0, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/chats/typing'))->count());
        $this->assertSame('ready', WaChannel::find($this->testChannel()->id)->status);
    }

    public function test_the_mode_needs_the_flag_and_a_known_value(): void
    {
        $this->assertSame('on_reply', ReadReceipts::mode());
        config(['features.env.read_receipts' => false]);
        $this->assertSame('off', ReadReceipts::mode());
        $this->assertSame('on_reply', ReadReceipts::configured());

        config(['features.env.read_receipts' => true]);
        WaBotSetting::current()->forceFill(['send_read_receipts' => 'always'])->save();
        $this->assertSame('off', ReadReceipts::mode());
    }
}
