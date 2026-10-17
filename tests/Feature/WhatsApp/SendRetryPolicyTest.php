<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\ReplyRun;
use App\Services\WhatsApp\WhatsAppChannel;
use Carbon\CarbonInterval;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الإعادة المحدودة (E19، العلم send_retry فوق send_ledger): لفشل الاتصال قبل الإرسال ولرموز الخنق والعابر وحدها،
 * بتأخير 4^X (ثانية ثم 4) ومجموع أقل من 10، وإعادة الأقفال قبل كل محاولة، ولا إعادة لما في NEVER_RETRY ولا بعد المهلة.
 */
class SendRetryPolicyTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.send_ledger' => true, 'features.env.send_retry' => true]);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        ReplyRun::clear();
        parent::tearDown();
    }

    private function metaError(int $code): array
    {
        return ['error' => ['code' => $code, 'message' => 'x', 'fbtrace_id' => 'AxR']];
    }

    private function ok(): PromiseInterface
    {
        return Http::response(['messages' => [['id' => 'wamid.RETRY.OK']]]);
    }

    private function send(string $source = 'bot', ?int $staffId = null, string $key = 'reply:1:9:text'): array
    {
        return WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'أهلاً', $source, $staffId, sendKey: $key);
    }

    private function seconds(int $whole): \Closure
    {
        return fn (CarbonInterval $d) => $d->totalMilliseconds >= $whole * 1000 && $d->totalMilliseconds <= $whole * 1000 + 250;
    }

    public function test_a_connection_refused_before_sending_is_retried_once_after_a_second(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->pushFailedConnection('cURL error 7: Failed to connect to graph.facebook.com port 443')
            ->push(['messages' => [['id' => 'wamid.RETRY.OK']]])]);

        $result = $this->send();

        $this->assertTrue($result['ok']);
        Http::assertSentCount(2);
        $this->assertSame([2, 'sent'], [WaMessage::sole()->attempts, WaMessage::sole()->status]);
        Sleep::assertSlept($this->seconds(1));
        Sleep::assertSleptTimes(1);
    }

    public function test_throttle_waits_one_then_four_seconds_for_the_bot(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push($this->metaError(130429), 400)->push($this->metaError(130429), 400)
            ->push(['messages' => [['id' => 'wamid.RETRY.OK']]])]);

        $this->assertTrue($this->send()['ok']);

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
        Sleep::assertSlept($this->seconds(1));
        Sleep::assertSlept($this->seconds(4));
        $this->assertSame(3, WaMessage::sole()->attempts);
    }

    public function test_staff_gets_one_extra_attempt_at_most(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push($this->metaError(130429), 400)->push($this->metaError(130429), 400)->push($this->metaError(130429), 400)]);
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();

        $result = $this->send('staff', $staff->id, 'staff:3f1c2b9e-5d4a-4c8b-9e7f-1a2b3c4d5e6f');

        Http::assertSentCount(2);
        $this->assertSame(['failed', null], [WaMessage::sole()->status, WaMessage::sole()->send_key]);
        $this->assertFalse($result['ok']);
    }

    public function test_a_transient_meta_error_is_retried_once_only(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push($this->metaError(131000), 500)->push($this->metaError(131000), 500)->push(['messages' => [['id' => 'wamid.X']]])]);

        $this->send();

        Http::assertSentCount(2);
        $this->assertSame('failed', WaMessage::sole()->status);
    }

    public static function neverRetried(): array
    {
        return array_combine(
            array_map(fn ($c) => "code {$c}", $codes = [131047, 131026, 368, 131031, 131048, 131049, 190, 0]),
            array_map(fn ($c) => [$c], $codes),
        );
    }

    #[DataProvider('neverRetried')]
    public function test_final_codes_are_sent_once(int $code): void
    {
        config(['services.whatsapp.health_autosuspend' => false]);
        Http::fake(['graph.facebook.com/*' => Http::response($this->metaError($code), 400)]);

        $this->send();

        Http::assertSentCount(1);
        $this->assertSame('failed', WaMessage::sole()->status);
        Sleep::assertNeverSlept();
    }

    public function test_a_request_timeout_is_never_retried(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::failedConnection('cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received')]);

        $this->send();

        Http::assertSentCount(1);
        $this->assertSame('unknown', WaMessage::sole()->status);
        Sleep::assertNeverSlept();
    }

    public function test_the_locks_are_read_again_before_a_retry(): void
    {
        $calls = 0;
        Http::fake(['graph.facebook.com/*' => function () use (&$calls) {
            $calls++;
            WaBotSetting::current()->forceFill(['wa_suspended' => true])->save();

            return Http::response($this->metaError(130429), 400);
        }]);

        $result = $this->send();

        $this->assertSame(1, $calls, 'لا طلب ثانٍ بعد الإيقاف');
        $row = WaMessage::sole();
        $this->assertSame(['failed', 'SENDING_SUSPENDED', null], [$row->status, $row->error_code, $row->send_key]);
        $this->assertSame('SENDING_SUSPENDED', $result['code']);
    }

    public function test_a_staff_reply_during_the_wait_drops_the_bot_retry(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $run = ReplyRun::start($conv, 'tok');
        ReplyRun::bind($run);
        Http::fake(['graph.facebook.com/*' => function () use ($conv) {
            WaMessage::factory()->fromStaff(User::factory()->create())->create(['conversation_id' => $conv->id]);

            return Http::response($this->metaError(130429), 400);
        }]);

        $result = WhatsAppChannel::sendText($conv, 'أهلاً', 'bot', sendKey: 'reply:1:1:text');

        Http::assertSentCount(1);
        $row = WaMessage::where('source', 'bot')->sole();
        $this->assertSame(['failed', 'REPLY_STALE', 'stale_takeover'], [$row->status, $row->error_code, data_get($row->payload, '_stale')]);
        $this->assertFalse($result['ok']);
    }

    public function test_no_retry_when_the_reply_budget_is_short(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        ReplyRun::bind(new ReplyRun('tok', 0, microtime(true) - (ReplyRun::BUDGET_SECONDS - 20)));
        Http::fake(['graph.facebook.com/*' => Http::response($this->metaError(130429), 400)]);

        WhatsAppChannel::sendText($conv, 'أهلاً', 'bot', sendKey: 'reply:1:1:text');

        Http::assertSentCount(1);
        $this->assertSame(['failed', null], [WaMessage::sole()->status, WaMessage::sole()->send_key]);
    }

    public function test_without_the_retry_flag_nothing_is_retried(): void
    {
        config(['features.env.send_retry' => false]);
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push($this->metaError(130429), 400)->push(['messages' => [['id' => 'w']]])]);

        $this->send();

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_every_attempt_reuses_the_row_and_the_opaque_value(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push($this->metaError(130429), 400)->push(['messages' => [['id' => 'w2']]])]);

        $this->send();

        $opaques = collect(Http::recorded())->map(fn ($pair) => $pair[0]['biz_opaque_callback_data'] ?? null)->unique()->values()->all();
        $this->assertSame(['nj:m:'.WaMessage::sole()->id], $opaques);
        $this->assertSame(1, WaMessage::count());
    }
}
