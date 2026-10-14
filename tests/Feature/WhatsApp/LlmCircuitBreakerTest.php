<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaLlmIncident;
use App\Services\WhatsApp\Llm\Exceptions\LlmAuthFailed;
use App\Services\WhatsApp\Llm\Exceptions\LlmBadRequest;
use App\Services\WhatsApp\Llm\Exceptions\LlmQuotaExhausted;
use App\Services\WhatsApp\Llm\Exceptions\LlmUnavailable;
use App\Services\WhatsApp\LlmCircuitBreaker as Breaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * قاطع مزوّد الذكاء (E12.3): الإخفاقات المتتالية تفتحه، والمفتاح المرفوض أو الحصة من أول مرة، وطلب البيانات
 * المرفوض لا يُحتسب؛ محاولة half-open واحدة؛ إشعار عند الفتح وعند العودة فقط؛ shadow يسجّل ولا يحجب.
 */
class LlmCircuitBreakerTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->configureChannel(['breaker_mode' => 'on']);
        User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function down(): LlmUnavailable
    {
        return new LlmUnavailable('google', 'Gemini HTTP 503: overloaded', 503);
    }

    private function notifications(): int
    {
        return Notification::count();
    }

    public function test_three_consecutive_failures_in_the_window_open_it_once(): void
    {
        Breaker::recordFailure('google', $this->down());
        Breaker::recordFailure('google', $this->down());
        $this->assertSame(Breaker::ALLOW, Breaker::allow('google'));

        Breaker::recordFailure('google', $this->down());

        $this->assertSame(Breaker::BLOCK, Breaker::allow('google'));
        $this->assertSame(1, $this->notifications());
        $incident = WaLlmIncident::sole();
        $this->assertSame(['google', 'unavailable', 503, false], [$incident->provider, $incident->kind, $incident->http_status, $incident->shadow]);
        $this->assertSame('2026-10-14 10:10:00', $incident->open_until->format('Y-m-d H:i:s'));
    }

    public function test_spread_out_failures_or_a_success_in_between_keep_it_closed(): void
    {
        Breaker::recordFailure('google', $this->down());
        Carbon::setTestNow(now()->addMinutes(3));
        Breaker::recordFailure('google', $this->down());
        Carbon::setTestNow(now()->addMinutes(3));
        Breaker::recordFailure('google', $this->down());
        $this->assertSame('closed', Breaker::status('google')['state'], 'الأول خرج من نافذة الخمس دقائق');

        Breaker::recordSuccess('google');
        Breaker::recordFailure('google', $this->down());
        $this->assertSame(['closed', 1], [Breaker::status('google')['state'], Breaker::status('google')['failures']]);
        $this->assertSame(0, WaLlmIncident::count());
    }

    public function test_a_rejected_key_or_exhausted_quota_opens_it_at_once_and_bad_requests_never_count(): void
    {
        foreach (range(1, 5) as $i) {
            Breaker::recordFailure('google', new LlmBadRequest('google', 'bad', 400));
        }
        $this->assertSame(Breaker::ALLOW, Breaker::allow('google'));

        Breaker::recordFailure('google', new LlmAuthFailed('google', 'key invalid', 400));
        $this->assertSame(Breaker::BLOCK, Breaker::allow('google'));

        Breaker::recordFailure('anthropic', new LlmQuotaExhausted('anthropic', 'spend cap', 429, 1800));
        $this->assertSame('2026-10-14 10:30:00', WaLlmIncident::where('provider', 'anthropic')->sole()->open_until->format('Y-m-d H:i:s'), 'retryAfter أطول من المدة');
    }

    public function test_after_the_open_period_one_probe_runs_and_success_closes_with_one_notice(): void
    {
        Breaker::recordFailure('google', new LlmAuthFailed('google', 'x', 401));
        Carbon::setTestNow(now()->addMinutes(11));

        $this->assertSame(Breaker::PROBE, Breaker::allow('google'));
        $this->assertSame(Breaker::BLOCK, Breaker::allow('google'));

        Breaker::countHandoff(Breaker::incidentId('google'));
        Breaker::recordSuccess('google');

        $this->assertSame(Breaker::ALLOW, Breaker::allow('google'));
        $incident = WaLlmIncident::sole();
        $this->assertSame(['probe', 1], [$incident->close_reason, $incident->handoffs]);
        $this->assertSame(2, $this->notifications(), 'فتح وعودة');
        $this->assertStringContainsString('1 محادثة', Notification::latest('id')->first()->body);
    }

    public function test_a_failed_probe_reopens_without_a_new_notice(): void
    {
        Breaker::recordFailure('google', new LlmAuthFailed('google', 'x', 401));
        Carbon::setTestNow(now()->addMinutes(11));
        $this->assertSame(Breaker::PROBE, Breaker::allow('google'));

        Breaker::recordFailure('google', $this->down());

        $this->assertSame(Breaker::BLOCK, Breaker::allow('google'));
        $this->assertSame([1, 1], [WaLlmIncident::sole()->failed_probes, $this->notifications()]);
    }

    public function test_shadow_records_but_never_blocks_or_notifies_and_off_records_nothing(): void
    {
        WaBotSetting::current()->forceFill(['breaker_mode' => 'shadow'])->save();
        Breaker::recordFailure('google', new LlmAuthFailed('google', 'x', 401));

        $this->assertSame(Breaker::ALLOW, Breaker::allow('google'));
        $this->assertTrue(WaLlmIncident::sole()->shadow);
        $this->assertSame(0, $this->notifications());
        $this->assertSame('closed', Breaker::publicStatus()['state'], 'الموظف لا يرى وضع المراقبة');

        WaLlmIncident::query()->delete();
        WaBotSetting::current()->forceFill(['breaker_mode' => 'off'])->save();
        Breaker::recordFailure('anthropic', new LlmAuthFailed('anthropic', 'x', 401));
        $this->assertSame(0, WaLlmIncident::count());
        $this->assertSame(Breaker::ALLOW, Breaker::allow('anthropic'));
    }

    public function test_manual_open_and_close(): void
    {
        $admin = User::sole();
        Breaker::forceOpen('google', 15, $admin->id);
        $this->assertSame(['open', 'manual'], [Breaker::status('google')['state'], Breaker::status('google')['kind']]);
        $this->assertSame('open', Breaker::publicStatus()['state']);
        $this->assertArrayNotHasKey('reason', Breaker::publicStatus());

        Breaker::forceClose('google', $admin->id);
        $this->assertSame(['manual', $admin->id, $admin->id], [WaLlmIncident::sole()->close_reason, WaLlmIncident::sole()->opened_by, WaLlmIncident::sole()->closed_by]);
    }

    public function test_the_probe_command_closes_the_breaker_without_any_customer_data(): void
    {
        $this->fakeGemini('pong');
        Breaker::recordFailure('google', $this->down());
        Breaker::recordFailure('google', $this->down());
        Breaker::recordFailure('google', $this->down());

        Artisan::call('whatsapp:llm-probe');
        Http::assertNothingSent();

        Carbon::setTestNow(now()->addMinutes(11));
        Artisan::call('whatsapp:llm-probe');

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => !str_contains($r->url(), 'key=') && $r->hasHeader('x-goog-api-key') && str_contains($r->body(), 'ping'));
        $this->assertSame('closed', Breaker::status('google')['state']);
        $this->assertSame('probe', WaLlmIncident::sole()->close_reason);
    }

    public function test_changing_the_model_closes_the_breaker(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        Breaker::recordFailure('google', new LlmAuthFailed('google', 'model gone', 404));

        $this->actingAs($owner)->patchJson('/whatsapp/settings', ['version' => (int) WaBotSetting::current()->fresh()->settings_version, 'changes' => ['model' => 'gemini-3.5-flash']])->assertOk();

        $this->assertSame('closed', Breaker::status('google')['state']);
        $this->assertSame('settings_changed', WaLlmIncident::sole()->close_reason);
    }
}
