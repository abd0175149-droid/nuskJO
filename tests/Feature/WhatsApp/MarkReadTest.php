<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\MetaErrorReactor;
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
 * إشعار القراءة (E35): طلب واحد على أحدث وارد، خارج dispatch (لا صف ولا مفتاح)، بالقفلين الأولين وحدهما
 * (القناة والإيقاف)، وفشله سطر وعدّاد لا يمس صحة القناة.
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

    private function graphCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com'))->count();
    }

    public function test_the_payload_is_exactly_a_read_status_on_the_newest_inbound(): void
    {
        $this->fakeGraph();
        $older = $this->in;
        $newest = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.IN.2']);

        $res = WhatsAppChannel::markRead($this->conv);

        $this->assertSame(['ok' => true, 'code' => null], $res);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v25.0/'.self::TEST_PHONE_NUMBER_ID.'/messages'
            && $r->data() === ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => 'wamid.IN.2']);
        $this->assertSame($newest->id, $this->conv->fresh()->read_receipt_upto_id);
        $this->assertGreaterThan($older->id, $this->conv->fresh()->read_receipt_upto_id);
    }

    public function test_typing_adds_the_typing_indicator_to_the_same_request(): void
    {
        $this->fakeGraph();

        WhatsAppChannel::markRead($this->conv, typing: true);

        Http::assertSent(fn (Request $r) => $r->data() === [
            'messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => 'wamid.IN.1', 'typing_indicator' => ['type' => 'text'],
        ]);
    }

    public function test_the_call_is_short_and_bounded(): void
    {
        $options = [];
        Http::fake(['graph.facebook.com/*' => function (Request $r, array $o) use (&$options) {
            $options = $o;

            return Http::response(['success' => true]);
        }]);

        WhatsAppChannel::markRead($this->conv);

        $this->assertSame(5, (int) $options['timeout']);
        $this->assertSame(2, (int) $options['connect_timeout']);
    }

    public function test_it_is_not_a_message_no_row_and_no_send_key(): void
    {
        $this->fakeGraph();
        $before = WaMessage::count();

        WhatsAppChannel::markRead($this->conv, typing: true);

        $this->assertSame($before, WaMessage::count());
        $this->assertSame(0, WaMessage::whereNotNull('send_key')->count());
        $this->assertNull($this->conv->fresh()->last_outbound_at);
    }

    public function test_it_ignores_the_24_hour_window_but_not_the_30_day_limit(): void
    {
        $this->fakeGraph();
        $this->conv->update(['last_inbound_at' => now()->subDays(3)]);
        $this->in->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->assertTrue(WhatsAppChannel::markRead($this->conv)['ok']);

        $this->in->forceFill(['created_at' => now()->subDays(31)])->save();
        $this->conv->update(['read_receipt_upto_id' => null]);
        $this->assertSame(['ok' => false, 'code' => 'NO_TARGET'], WhatsAppChannel::markRead($this->conv));
        $this->assertSame(1, $this->graphCalls());
    }

    public function test_reactions_and_system_messages_are_never_the_target(): void
    {
        $this->fakeGraph();
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.REACT', 'msg_type' => 'reaction', 'body' => '👍']);
        WaMessage::factory()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.SYS', 'direction' => 'in', 'source' => 'system', 'msg_type' => 'system']);

        WhatsAppChannel::markRead($this->conv);

        Http::assertSent(fn (Request $r) => $r['message_id'] === 'wamid.IN.1');
        $this->assertSame(1, $this->graphCalls());
    }

    public function test_upto_limits_the_target_to_what_existed_then(): void
    {
        $this->fakeGraph();
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.IN.LATER']);

        WhatsAppChannel::markRead($this->conv, false, $this->in->id);

        Http::assertSent(fn (Request $r) => $r['message_id'] === 'wamid.IN.1');
        $this->assertSame($this->in->id, $this->conv->fresh()->read_receipt_upto_id);
    }

    public function test_a_second_receipt_without_typing_on_the_same_message_makes_no_request(): void
    {
        $this->fakeGraph();

        $this->assertTrue(WhatsAppChannel::markRead($this->conv)['ok']);
        $this->assertSame(['ok' => false, 'code' => 'ALREADY'], WhatsAppChannel::markRead($this->conv));
        // المؤشر يُجدَّد على الرسالة نفسها
        $this->assertTrue(WhatsAppChannel::markRead($this->conv, typing: true)['ok']);

        $this->assertSame(2, $this->graphCalls());
    }

    public function test_the_mark_only_moves_forward(): void
    {
        $this->fakeGraph();
        $newest = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.IN.2']);
        $this->conv->update(['read_receipt_upto_id' => $newest->id]);

        // مؤشر على رسالة أقدم (upto): ينجح ولا يعيد العلامة إلى الوراء
        WhatsAppChannel::markRead($this->conv, true, $this->in->id);

        $this->assertSame($newest->id, $this->conv->fresh()->read_receipt_upto_id);
    }

    public function test_nothing_is_sent_when_the_feature_is_off_or_a_first_lock_holds(): void
    {
        $this->fakeGraph();

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

        WaBotSetting::current()->forceFill(['wa_token' => null])->save();
        $this->assertSame(WhatsAppChannel::ERR_NOT_CONFIGURED, WhatsAppChannel::markRead($this->conv)['code']);

        $this->configureChannel(['send_read_receipts' => 'on_reply']);
        $this->conv->update(['status' => 'merged']);
        $this->assertSame('MERGED', WhatsAppChannel::markRead($this->conv)['code']);

        Http::assertNothingSent();
        $this->assertNull($this->conv->fresh()->read_receipt_upto_id);
    }

    public function test_a_rejected_receipt_is_logged_and_counted_without_touching_channel_health(): void
    {
        $this->captureLogs();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
            'message' => '(#131009) Parameter value is not valid', 'type' => 'OAuthException', 'code' => 131009, 'fbtrace_id' => 'TRACE1',
        ]], 400)]);
        $healthBefore = WaBotSetting::current()->wa_channel_health;

        $res = WhatsAppChannel::markRead($this->conv);

        $this->assertFalse($res['ok']);
        $this->assertNotNull($res['code']);
        $s = WaBotSetting::current()->fresh();
        $this->assertNull($s->wa_last_error);
        $this->assertEquals($healthBefore, $s->wa_channel_health);
        $this->assertFalse((bool) $s->wa_suspended);
        $this->assertNull($this->conv->fresh()->read_receipt_upto_id);
        $this->assertSame(1, ReadReceipts::failuresToday());
        $this->assertSame(0, array_sum(MetaErrorReactor::categoryCounts()));
        $this->assertStringContainsString('131009', (string) ReadReceipts::lastFailureCode());
        $this->assertLogged('info', 'wa.read_receipt_failed', ['conv_id' => $this->conv->id, 'code' => $res['code'], 'meta_code' => 131009, 'fbtrace_id' => 'TRACE1', 'http' => 400]);
        // لا نص ولا رقم في السطر
        $line = collect($this->logged)->firstWhere('message', 'wa.read_receipt_failed');
        $this->assertSame([], array_diff(array_keys($line['context']), ['conv_id', 'code', 'meta_code', 'fbtrace_id', 'http']));
    }

    public function test_a_timeout_is_a_quiet_failure_too(): void
    {
        Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds')]);

        $res = WhatsAppChannel::markRead($this->conv, typing: true);

        $this->assertSame(['ok' => false, 'code' => 'HTTP_TIMEOUT'], $res);
        $this->assertNull(WaBotSetting::current()->fresh()->wa_last_error);
        $this->assertSame(1, ReadReceipts::failuresToday());
        // عدّادات فئات أخطاء ميتا (بطاقة الصحة) لا تتحرك
        $this->assertSame(0, array_sum(MetaErrorReactor::categoryCounts()));
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
