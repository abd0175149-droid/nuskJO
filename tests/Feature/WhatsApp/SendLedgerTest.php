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
 * دفتر الإرسال (E19، العلم send_ledger): الصف queued يُكتب قبل النداء ثم يُحسم الصف نفسه، وbiz_opaque_callback_data
 * برقم الصف وحده، والرفض الصريح يحرّر المفتاح، والمهلة و5xx بلا JSON والـ200 بلا معرّف تحجزه «غير مؤكد».
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

    public function test_the_row_is_queued_with_its_key_before_meta_answers_then_becomes_sent(): void
    {
        $seen = null;
        Http::fake(['graph.facebook.com/*' => function (Request $r) use (&$seen) {
            $seen = WaMessage::sole()->only(['status', 'send_key']) + ['opaque' => $r['biz_opaque_callback_data'] ?? null];

            return Http::response(['messages' => [['id' => 'wamid.LEDGER.1']]]);
        }]);

        $result = WhatsAppChannel::sendText($this->conv(), 'أهلاً', 'bot', sendKey: self::KEY);

        $row = WaMessage::sole();
        $this->assertSame(['status' => 'queued', 'send_key' => self::KEY, 'opaque' => 'nj:m:'.$row->id], $seen);
        $this->assertSame(['sent', 'wamid.LEDGER.1', 1, self::KEY], [$row->status, $row->wamid, $row->attempts, $row->send_key]);
        $this->assertNotNull($row->sent_at);
        $this->assertSame([true, 'sent', $row->id, false, 'wamid.LEDGER.1'],
            [$result['ok'], $result['status'], $result['row_id'], $result['replayed'], $result['message_id']]);
        $this->assertArrayNotHasKey('_req_hash', $result);
    }

    public function test_the_opaque_value_carries_the_row_number_only(): void
    {
        $this->fakeGraph();
        $conv = WaConversation::factory()->windowOpen()->create(['wa_phone' => '16505551234']);

        WhatsAppChannel::sendText($conv, 'رقمي 0791234567', 'bot', sendKey: self::KEY);

        Http::assertSent(function (Request $r) {
            $opaque = (string) ($r['biz_opaque_callback_data'] ?? '');

            return preg_match('/^nj:m:\d+$/', $opaque) === 1 && strlen($opaque) < 512 && !str_contains($opaque, '16505551234');
        });
    }

    public function test_an_explicit_meta_rejection_fails_and_releases_the_key_for_a_legitimate_retry(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400)
            ->push(['messages' => [['id' => 'wamid.LEDGER.2']]])]);
        $conv = $this->conv();

        $first = WhatsAppChannel::sendText($conv, 'أهلاً', 'bot', sendKey: self::KEY);

        $failed = WaMessage::sole();
        $this->assertSame(['failed', null, 131047, 'WINDOW_EXPIRED'], [$failed->status, $failed->send_key, $failed->meta_code, $failed->error_code]);
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
        Http::fake(['graph.facebook.com/*' => Http::failedConnection('cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received')]);
        $conv = $this->conv();

        $first = WhatsAppChannel::sendText($conv, 'أهلاً', 'bot', sendKey: self::KEY);

        $row = WaMessage::sole();
        $this->assertSame(['unknown', self::KEY, 'HTTP_TIMEOUT', 1], [$row->status, $row->send_key, $row->error_code, $row->attempts]);
        $this->assertSame(['unknown', true, false], [$first['status'], $first['unknown'], $first['ok']]);
        Http::assertSentCount(1);

        $again = WhatsAppChannel::sendText($conv, 'أهلاً', 'bot', sendKey: self::KEY);

        $this->assertSame(['OUTCOME_UNKNOWN', 'unknown'], [$again['code'], $again['status']]);
        Http::assertSentCount(1);
        $this->assertSame(1, WaMessage::count());
    }

    public function test_an_html_502_and_a_200_without_id_are_unknown(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push('<html>Bad Gateway</html>', 502)
            ->push(['messaging_product' => 'whatsapp'])]);
        $conv = $this->conv();

        WhatsAppChannel::sendText($conv, 'أولى', 'bot', sendKey: 'reply:1:1:text');
        WhatsAppChannel::sendText($conv, 'ثانية', 'bot', sendKey: 'reply:1:2:text');

        $this->assertSame([['unknown', 'HTTP_5XX_NO_BODY'], ['unknown', 'OUTCOME_UNKNOWN']],
            WaMessage::orderBy('id')->get()->map(fn ($m) => [$m->status, $m->error_code])->all());
        Http::assertSentCount(2);
    }

    public function test_a_sent_key_replays_without_calling_meta(): void
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

    public function test_the_same_key_with_another_text_is_refused(): void
    {
        $this->fakeGraph();
        $conv = $this->conv();
        WhatsAppChannel::sendText($conv, 'أهلاً', 'bot', sendKey: self::KEY);

        $other = WhatsAppChannel::sendText($conv, 'نص آخر', 'bot', sendKey: self::KEY);

        $this->assertSame(['IDEMPOTENCY_MISMATCH', false], [$other['code'], $other['ok']]);
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
        $closed = WaConversation::factory()->create(['last_inbound_at' => now()->subDays(2)]);
        $this->assertSame('WINDOW_EXPIRED', WhatsAppChannel::sendText($closed, 'أهلاً', 'bot', sendKey: self::KEY)['code']);

        WaBotSetting::current()->forceFill(['wa_suspended' => true])->save();
        $this->assertSame('SENDING_SUSPENDED', WhatsAppChannel::sendText($this->conv(), 'أهلاً', 'bot', sendKey: self::KEY)['code']);

        $this->assertSame(0, WaMessage::count());
        Http::assertNothingSent();
    }

    public function test_a_staff_timeout_pauses_the_bot_as_if_sent(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::failedConnection('cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received')]);
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $conv = $this->conv();

        WhatsAppChannel::sendText($conv, 'موعدك غداً', 'staff', $staff->id, sendKey: 'staff:3f1c2b9e-5d4a-4c8b-9e7f-1a2b3c4d5e6f');

        $this->assertNotNull($conv->fresh()->bot_paused_until);
        $this->assertSame('unknown', WaMessage::sole()->status);
    }

    public function test_without_the_flag_the_old_path_stores_after_the_answer(): void
    {
        config(['features.env.send_ledger' => false]);
        $seen = 'not-called';
        Http::fake(['graph.facebook.com/*' => function (Request $r) use (&$seen) {
            $seen = WaMessage::count().'|'.($r['biz_opaque_callback_data'] ?? 'none');

            return Http::response(['messages' => [['id' => 'wamid.OLD.1']]]);
        }]);

        WhatsAppChannel::sendText($this->conv(), 'أهلاً', 'bot', sendKey: self::KEY);

        $this->assertSame('0|none', $seen);
        $this->assertSame(['sent', null], [WaMessage::sole()->status, WaMessage::sole()->send_key]);
    }
}
