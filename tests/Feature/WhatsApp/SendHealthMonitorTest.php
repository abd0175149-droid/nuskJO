<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\RefreshChannelHealth;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Services\Ops\OpsHealth;
use App\Services\WhatsApp\SendHealthMonitor;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * جهة الإرسال (E22): فشل متتالٍ ينبّه عند الثالث ثم كل خامس ولا يحتسب أخطاء المستلم، و368 يحجب من أول مرة بتنبيه واحد،
 * و190 رمز باطل بتنبيه واحد، وقاطع التكرار ينبّه ويفحص (ويحجب بعلمه وحده). لا يُكتب wa_suspended ولا رفع آلياً.
 */
class SendHealthMonitorTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $admin;

    private WaConversation $conv;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['features.env.channel_health' => true]);
        $this->configureChannel();
        $this->admin = User::factory()->admin()->create();
        $this->conv = WaConversation::factory()->windowOpen()->create();
    }

    private function metaError(int $code, string $type = 'OAuthException'): array
    {
        return ['error' => ['code' => $code, 'type' => $type, 'message' => 'Error '.$code, 'fbtrace_id' => 'AxE22Test'.$code]];
    }

    /** @param list<array{0: array, 1: int}> $responses */
    private function respond(array $responses): void
    {
        $seq = Http::sequence();
        foreach ($responses as [$body, $status]) {
            $seq->push($body, $status);
        }
        Http::fake(['graph.facebook.com/*' => $seq]);
    }

    private function send(): array
    {
        return WhatsAppChannel::sendText($this->conv->fresh(), 'مرحباً', 'system', sendKey: SendKey::optout('wamid.E22.'.(++$this->n)));
    }

    private function titles(): array
    {
        return Notification::where('user_id', $this->admin->id)->orderBy('id')->pluck('title')->all();
    }

    private function ok(): array
    {
        return [['messages' => [['id' => 'wamid.OK.'.uniqid()]]], 200];
    }

    public function test_consecutive_failures_alert_at_the_third_then_every_fifth_and_a_success_resets(): void
    {
        $fail = [$this->metaError(131000), 500];
        $this->respond([$fail, $fail, $this->ok(), $fail, $fail, $fail, $fail, $fail, $fail, $fail, $fail, $fail]);

        $this->send();
        $this->send();
        $this->send();
        $this->assertSame(0, SendHealthMonitor::consecutive(), 'النجاح يصفّر العدّاد');
        foreach (range(1, 9) as $i) {
            $this->send();
        }

        $this->assertSame(9, SendHealthMonitor::consecutive());
        $this->assertSame(['⚠️ فشل الإرسال إلى ميتا 3 مرات متتالية', '⚠️ فشل الإرسال إلى ميتا 8 مرات متتالية'], $this->titles());
        $this->assertStringContainsString('آخر رمز 131000', Notification::where('title', '⚠️ فشل الإرسال إلى ميتا 3 مرات متتالية')->value('body'));
    }

    public function test_recipient_and_window_errors_are_not_counted(): void
    {
        $this->respond(array_fill(0, 6, [$this->metaError(131026), 400]));
        foreach (range(1, 3) as $i) {
            $this->send();
        }
        $this->respond(array_fill(0, 3, [$this->metaError(131047), 400]));
        foreach (range(1, 3) as $i) {
            $this->send();
        }

        $this->assertSame(0, SendHealthMonitor::consecutive());
        $this->assertSame([], $this->titles());
    }

    public function test_connection_failures_are_counted_without_changing_health(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::failedConnection()]);

        foreach (range(1, 3) as $i) {
            $this->send();
        }

        $this->assertSame(3, SendHealthMonitor::consecutive());
        $this->assertSame('UNKNOWN', WaBotSetting::current()->fresh()->wa_health);
        $this->assertSame(['⚠️ فشل الإرسال إلى ميتا 3 مرات متتالية'], $this->titles());
    }

    public function test_368_blocks_from_the_first_time_with_one_alert_in_both_error_modes(): void
    {
        $this->respond([[$this->metaError(368), 400], [$this->metaError(368), 400]]);
        foreach ([false, true] as $v2) {
            config(['features.env.meta_error_v2' => $v2]);
            WaBotSetting::current()->forceFill(['wa_health' => 'AVAILABLE'])->save();
            Notification::query()->delete();
            OpsHealth::store()->flush();

            $this->send();

            $s = WaBotSetting::current()->fresh();
            $this->assertSame('BLOCKED', $s->wa_health, $v2 ? 'v2' : 'legacy');
            $this->assertSame('send_error', $s->wa_health_source);
            $this->assertStringStartsWith('integrity:368', $s->wa_health_reason);
            $this->assertFalse((bool) $s->wa_suspended);
            $this->assertSame(['⛔ ميتا حجبت الإرسال على رقم الشركة'], $this->titles(), 'لا تنبيه ثانٍ من المفاعل أو الجدول القديم');
        }
    }

    public function test_190_is_token_invalid_with_a_single_alert(): void
    {
        $this->respond([[$this->metaError(190), 401]]);

        $this->send();

        $this->assertSame('TOKEN_INVALID', WaBotSetting::current()->fresh()->wa_health);
        $this->assertSame(['🔑 رمز ميتا غير صالح — جدّد System User token'], $this->titles());
    }

    public function test_token_invalid_blocks_sending_only_with_its_flag(): void
    {
        WaBotSetting::current()->forceFill(['wa_health' => 'TOKEN_INVALID'])->save();
        $this->respond([$this->ok()]);
        $this->assertTrue($this->send()['ok']);

        config(['features.env.channel_health_block_on_token' => true]);
        Http::fake();
        $res = $this->send();

        $this->assertSame(WhatsAppChannel::ERR_TOKEN, $res['code']);
        Http::assertNothingSent();
    }

    public function test_the_integrity_breaker_alerts_and_polls_in_alert_mode(): void
    {
        $this->respond(array_fill(0, 4, [$this->metaError(131048), 400]));

        foreach (range(1, 4) as $i) {
            $this->send();
        }

        $this->assertSame('UNKNOWN', WaBotSetting::current()->fresh()->wa_health);
        // تنبيه القاطع مرة واحدة في النافذة (والعدّاد المتتالي يحتسب النزاهة أيضاً)
        $this->assertSame(1, collect($this->titles())->filter(fn ($t) => $t === '⛔ ميتا ترفض الإرسال لأسباب النزاهة بشكل متكرر')->count());
        Queue::assertPushed(RefreshChannelHealth::class, 1);
    }

    public function test_the_integrity_breaker_blocks_in_lock_mode_and_never_lifts_itself(): void
    {
        config(['features.env.integrity_breaker_lock' => true]);
        $this->respond(array_merge(array_fill(0, 3, [$this->metaError(131031), 400]), [$this->ok()]));

        foreach (range(1, 3) as $i) {
            $this->send();
        }

        $this->assertSame('BLOCKED', WaBotSetting::current()->fresh()->wa_health);
        $this->assertStringStartsWith('integrity:131031', WaBotSetting::current()->fresh()->wa_health_reason);
        $res = $this->send();
        $this->assertSame(WhatsAppChannel::ERR_CHANNEL_BLOCKED, $res['code']);
        $this->assertSame('BLOCKED', WaBotSetting::current()->fresh()->wa_health);
    }

    public function test_a_success_clears_a_last_error_from_sending_only(): void
    {
        config(['features.env.meta_error_v2' => true]);
        $this->respond([[$this->metaError(130429), 400], $this->ok(), $this->ok()]);
        $this->send();
        $this->assertSame(130429, WaBotSetting::current()->fresh()->wa_last_error['meta_code']);

        $this->send();
        $this->assertNull(WaBotSetting::current()->fresh()->wa_last_error);

        WaBotSetting::current()->forceFill(['wa_last_error' => ['meta_code' => 131000, 'source' => 'smoke', 'at' => now()->toIso8601String()]])->save();
        $this->send();
        $this->assertSame('smoke', WaBotSetting::current()->fresh()->wa_last_error['source']);
    }

    public function test_country_restriction_alerts_and_is_remembered_without_a_transition(): void
    {
        $this->respond([[$this->metaError(130497), 400]]);

        $this->send();

        $s = WaBotSetting::current()->fresh();
        $this->assertSame('UNKNOWN', $s->wa_health);
        $this->assertArrayHasKey('country_restricted', $s->wa_health_meta);
        $this->assertSame(['⚠️ ميتا تمنع المراسلة إلى بلد أحد العملاء'], $this->titles());
    }

    public function test_with_the_flag_off_nothing_is_counted_and_368_suspends_the_old_way(): void
    {
        config(['features.env.channel_health' => false, 'features.env.meta_error_v2' => true]);
        $this->respond([[$this->metaError(368), 400]]);

        $this->send();

        $s = WaBotSetting::current()->fresh();
        $this->assertTrue((bool) $s->wa_suspended);
        $this->assertSame('meta_event', $s->wa_suspended_source);
        $this->assertSame('UNKNOWN', $s->wa_health);
        $this->assertSame(0, SendHealthMonitor::consecutive());
    }
}
