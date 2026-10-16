<?php

namespace Tests\Feature\Ops;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaBotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\PatchesWhatsAppSettings;
use Tests\TestCase;

/**
 * الإصدار المختوم (E24): الـ commit ووقت البناء من الصورة يظهران للمدير في الإعدادات وwhatsapp:doctor وops:status،
 * ولا يظهران في /up. وحدود الصادر حقول في SettingsSchema تُحفظ عبر SettingsWriter وتُدقَّق.
 */
class BuildStampAndLimitsTest extends TestCase
{
    use PatchesWhatsAppSettings;
    use RefreshDatabase;

    public function test_the_image_and_the_deploy_script_stamp_the_build(): void
    {
        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
        $deploy = (string) file_get_contents(base_path('scripts/deploy.sh'));

        $this->assertStringContainsString('ARG BUILD_TIME=unknown', $dockerfile);
        $this->assertMatchesRegularExpression('/ENV APP_COMMIT=\$GIT_COMMIT APP_BUILD_TIME=\$BUILD_TIME/', $dockerfile);
        $this->assertSame(2, substr_count($deploy, '--build-arg GIT_COMMIT="$SHA" --build-arg BUILD_TIME="$BUILD_TIME"'));
        $this->assertStringContainsString('BUILD_TIME="$(date -u +%FT%TZ)"', $deploy);
    }

    public function test_the_admin_sees_the_build_and_the_public_health_route_does_not(): void
    {
        config(['app.build' => ['sha' => 'abc1234', 'time' => '2026-10-16T08:40:00Z']]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/whatsapp/settings')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('build.sha', 'abc1234')->where('build.time', '2026-10-16T08:40:00Z')
                ->has('outbound.today')->where('outbound.system_daily_cap', 50));
        $this->assertStringNotContainsString('abc1234', $this->get('/up')->getContent());

        Artisan::call('whatsapp:doctor');
        $this->assertStringContainsString('الإصدار: abc1234 — بُني 2026-10-16T08:40:00Z', Artisan::output());
        Artisan::call('ops:status');
        $this->assertStringContainsString('الإصدار: abc1234', Artisan::output());
    }

    public function test_doctor_warns_about_an_unstamped_build_in_production(): void
    {
        config(['app.build' => ['sha' => 'unknown', 'time' => null]]);
        $this->app->detectEnvironment(fn () => 'production');

        Artisan::call('whatsapp:doctor');

        $this->assertStringContainsString('BUILD_UNSTAMPED', Artisan::output());
    }

    public function test_the_limits_are_saved_through_the_settings_writer_and_audited(): void
    {
        $admin = User::factory()->admin()->create();
        WaBotSetting::current();

        $this->patchSettings($admin, ['wa_daily_alert_threshold' => 500, 'wa_system_daily_cap' => 0, 'wa_service_rate_usd' => 0.0045])->assertOk();

        $s = WaBotSetting::current()->fresh();
        $this->assertSame([500, 0, 0.0045], [$s->wa_daily_alert_threshold, $s->wa_system_daily_cap, $s->wa_service_rate_usd]);
        $audit = AuditLog::where('action', AuditAction::WaSettingsUpdate->value)->latest('id')->first();
        $this->assertSame(['from' => 50, 'to' => 0], $audit->changes['changed']['wa_system_daily_cap']);

        $this->patchSettings($admin, ['wa_service_rate_usd' => 2])->assertStatus(422);
        $this->patchSettings($admin, ['wa_system_daily_cap' => -1])->assertStatus(422);
        $this->patchSettings($admin, ['wa_service_rate_usd' => null])->assertOk();
        $this->assertNull(WaBotSetting::current()->fresh()->wa_service_rate_usd);
    }
}
