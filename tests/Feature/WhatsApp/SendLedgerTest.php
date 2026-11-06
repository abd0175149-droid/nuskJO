<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * دفتر الإرسال (E19، دائماً منذ 2026-11-06): الصف queued يُكتب قبل النداء ثم يُحسم الصف نفسه، وIdempotency-Key برقم
 * الصف وحده، والرفض الصريح قبل المحرك يحرّر المفتاح، والمهلة وخطأ المحرك والرد بلا معرّف تحجزه «غير مؤكد».
 */
class SendLedgerTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const KEY = 'reply:1:10:text';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.send_ledger' => true]);
    }

    private function conv(): WaConversation
    {
        return WaConversation::factory()->windowOpen()->create();
    }

    /** بوابة وهمية بلا fakeGateway: الرد كما يُعطى (رد أو تسلسل أو دالة) */
    private function gateway(mixed $response): void
    {
        config(['whatsapp.openwa.url' => 'http://nuskjo-openwa:2785', 'whatsapp.transport' => 'openwa']);
        Http::fake(['nuskjo-openwa:2785/*' => $response]);
    }

    public function test_the_row_is_queued_with_its_key_before_the_gateway_answers_then_becomes_sent(): void
    {
        $seen = null;
        $this->gateway(function (Request $r) use (&$seen) {
            $seen = WaMessage::sole()->only(['status', 'send_key']) + ['idem' => $r->header('Idempotency-Key')[0] ?? null];

            return Http::response(['messageId' => 'wamid.LEDGER.1'], 201);
        });

        $result = WhatsAppChannel::sendText($this->conv(), 'أهلاً', 'bot', sendKey: self::KEY);

        $row = WaMessage::sole();
        $this->assertSame(['status' => 'queued', 'send_key' => self::KEY, 'idem' => 'nj-m-'.$row->id], $seen);
        $this->assertSame(['sent', 'wamid.LEDGER.1', 1, self::KEY], [$row->status, $row->wamid, $row->attempts, $row->send_key]);
        $this->assertNotNull($row->sent_at);
        $this->assertSame([true, 'sent', $row->id, false, 'wamid.LEDGER.1'],
            [$result['ok'], $result['status'], $result['row_id'], $result['replayed'], $result['message_id']]);
        $this->assertArrayNotHasKey('_req_hash', $result);
    }

    public function test_the_idempotency_key_carries_the_row_number_only(): void
    {
        $this->fakeGraph();
        $conv = WaConversation::factory()->windowOpen()->create(['wa_phone' => '16505551234']);

        WhatsAppChannel::sendText($conv, 'رقمي 0791234567', 'bot', sendKey: self::KEY);

        $key = (string) ($this->gatewaySends()[0]['headers']['Idempotency-Key'][0] ?? '');
        $this->assertMatchesRegularExpression('/^nj-m-\d+$/', $key);
        $this->assertStringNotContainsString('16505551234', $key);
    }

    public function test_an_explicit_gateway_rejection_fails_and_releases_the_key_for_a_legitimate_retry(): void
    {
        $this->gateway(Http::sequence()
            ->push(['statusCode' => 400, 'message' => ['text must be a string']], 400)
            ->push(['messageId' => 'wamid.LEDGER.2'], 201));
        $conv = $this->conv();

        $first = WhatsAppChannel::sendText($conv, 'أهلاً', 'bot', sendKey: self::KEY);

        $failed = WaMessage::sole();
        $this->assertSame(['failed', null, 'GW_REJECTED'], [$failed->status, $failed->send_key, $failed->error_code]);
        $this->assertSame(self::KEY, data_get($failed->payload, '_released_key'));
        $this->assertSame(['failed', false], [$first['status'], $first['ok']]);
        Http::assertSentCount(1);

        $second = WhatsAppChannel::sendText($conv, 'أهلاً', 'bot', sendKey: self::KEY);

        $this->assertTrue($second['ok']);
        $this->assertSame(2, WaMessage::count(), 'إعادة مشروعة بعد التحرير: صف جديد');
        Http::assertSentCount(2);
    }

    public function test_a_timeout_is_unknown_keeps_the_key_and_is_never_resent(): void
    {
        $this->gateway(Http::failedConnection('cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received'));
        $conv = $this->conv();

        $first = WhatsAppChannel::sendText($conv, 'أهلاً', 'bot', sendKey: self::KEY);

        $row = WaMessage::sole();
        $this->assertSame(['unknown', self::KEY, 'GW_TIMEOUT', 1], [$row->status, $row->send_key, $row->error_code, $row->attempts]);
        $this->assertSame(['unknown', true, false], [$first['status'], $first['unknown'], $first['ok']]);
        Http::assertSentCount(1);

        $again = WhatsAppChannel::sendText($conv, 'أهلاً', 'bot', sendKey: self::KEY);

        $this->assertSame(['OUTCOME_UNKNOWN', 'unknown'], [$again['code'], $again['status']]);
        Http::assertSentCount(1);
        $this->assertSame(1, WaMessage::count());
    }

    public function test_an_engine_502_and_a_201_without_id_are_unknown(): void
    {
        $this->gateway(Http::sequence()
            ->push('<html>Bad Gateway</html>', 502)
            ->push(['success' => true], 201));
        $conv = $this->conv();

        WhatsAppChannel::sendText($conv, 'أولى', 'bot', sendKey: 'reply:1:1:text');
        WhatsAppChannel::sendText($conv, 'ثانية', 'bot', sendKey: 'reply:1:2:text');

        $this->assertSame([['unknown', 'GW_ERROR'], ['unknown', 'OUTCOME_UNKNOWN']],
            WaMessage::orderBy('id')->get()->map(fn ($m) => [$m->status, $m->error_code])->all());
        Http::assertSentCount(2);
    }

    public function test_a_sent_key_replays_without_calling_the_gateway(): void
    {
        $this->fakeGraph();
        $conv = $this->conv();

        $first = WhatsAppChannel::sendText($conv, 'أهلاً', 'bot', sendKey: self::KEY);
        $again = WhatsAppChannel::sendText($conv, 'أهلاً', 'bot', sendKey: self::KEY);

        $this->assertSame([true, 'replayed', true, $first['message_id'], $first['row_id']],
            [$again['ok'], $again['status'], $again['replayed'], $again['message_id'], $again['row_id']]);
        Http::assertSentCount(1);
        $this->assertSame(1, WaMessage::count());
    }

    public function test_the_same_staff_key_with_another_text_is_refused(): void
    {
        $this->fakeGraph();
        $conv = $this->conv();
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $key = 'staff:3f1c2b9e-5d4a-4c8b-9e7f-1a2b3c4d5e6f';
        WhatsAppChannel::sendText($conv, 'أهلاً', 'staff', $staff->id, sendKey: $key);

        $other = WhatsAppChannel::sendText($conv, 'نص آخر', 'staff', $staff->id, sendKey: $key);

        $this->assertSame(['IDEMPOTENCY_MISMATCH', false], [$other['code'], $other['ok']]);
        Http::assertSentCount(1);
    }

    public function test_a_regenerated_bot_reply_for_the_same_messages_replays(): void
    {
        $this->fakeGraph();
        $conv = $this->conv();
        WhatsAppChannel::sendText($conv, 'أهلاً', 'bot', sendKey: self::KEY);

        $again = WhatsAppChannel::sendText($conv, 'مرحباً، كيف أخدمك؟', 'bot', sendKey: self::KEY);

        $this->assertSame([true, true], [$again['ok'], $again['replayed']]);
        Http::assertSentCount(1);
    }

    public function test_a_queued_key_is_in_progress(): void
    {
        $this->fakeGraph();
        $conv = $this->conv();
        WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => 'queued', 'send_key' => self::KEY,
            'body' => 'أهلاً', 'payload' => ['_req_hash' => hash('sha256', $conv->id.'|text|أهلاً')]]);

        $result = WhatsAppChannel::sendText($conv, 'أهلاً', 'bot', sendKey: self::KEY);

        $this->assertSame(['SEND_IN_PROGRESS', 'queued'], [$result['code'], $result['status']]);
        Http::assertNothingSent();
    }

    public function test_locks_refuse_before_any_row_or_key(): void
    {
        $this->fakeGraph();
        $this->testChannel()->update(['paused_until' => now()->addHour()]);
        $this->assertSame('CHANNEL_PAUSED', WhatsAppChannel::sendText($this->conv(), 'أهلاً', 'bot', sendKey: self::KEY)['code']);
        $this->testChannel()->update(['paused_until' => null, 'status' => 'disconnected']);
        $this->assertSame('CHANNEL_NOT_READY', WhatsAppChannel::sendText($this->conv(), 'أهلاً', 'bot', sendKey: self::KEY)['code']);
        $this->testChannel()->update(['status' => 'ready']);

        WaBotSetting::current()->forceFill(['wa_suspended' => true])->save();
        $this->assertSame('SENDING_SUSPENDED', WhatsAppChannel::sendText($this->conv(), 'أهلاً', 'bot', sendKey: self::KEY)['code']);

        $this->assertSame(0, WaMessage::count());
        Http::assertNothingSent();
    }

    public function test_a_staff_timeout_pauses_the_bot_as_if_sent(): void
    {
        $this->gateway(Http::failedConnection('cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received'));
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $conv = $this->conv();

        WhatsAppChannel::sendText($conv, 'موعدك غداً', 'staff', $staff->id, sendKey: 'staff:3f1c2b9e-5d4a-4c8b-9e7f-1a2b3c4d5e6f');

        $this->assertNotNull($conv->fresh()->bot_paused_until);
        $this->assertSame('unknown', WaMessage::sole()->status);
    }
}
