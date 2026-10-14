<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/** تنبيه نهاية دعم إصدار Graph، والفحص القرائي، ولوحة الإصدارات في الإعدادات. */
class GraphVersionCheckTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private function alerts(User $admin): int
    {
        return Notification::where('user_id', $admin->id)->where('action_url', '/whatsapp/settings')->count();
    }

    public function test_no_alert_while_both_versions_are_supported(): void
    {
        $admin = User::factory()->admin()->create();
        Carbon::setTestNow('2026-10-14 08:00');
        config(['services.whatsapp.graph_version' => 'v25.0', 'services.whatsapp.webhook_version' => 'v25.0']);

        $this->artisan('whatsapp:graph-version-check')->expectsOutputToContain('لا تنبيه جديد')->assertExitCode(0);

        $this->assertSame(0, $this->alerts($admin));
    }

    public function test_alerts_once_a_day_within_sixty_days_of_sunset(): void
    {
        $admin = User::factory()->admin()->create();
        Carbon::setTestNow('2026-12-01 08:00');   // v21.0 ينتهي 2027-01-21
        config(['services.whatsapp.graph_version' => 'v21.0', 'services.whatsapp.webhook_version' => 'v25.0']);

        $this->artisan('whatsapp:graph-version-check')->expectsOutputToContain('بعد 51 يوماً')->assertExitCode(0);
        $this->artisan('whatsapp:graph-version-check')->assertExitCode(0);
        $this->assertSame(1, $this->alerts($admin));

        Carbon::setTestNow('2026-12-02 08:00');
        $this->artisan('whatsapp:graph-version-check')->assertExitCode(0);
        $this->assertSame(2, $this->alerts($admin));
    }

    public function test_unset_webhook_version_and_unknown_versions_alert(): void
    {
        $admin = User::factory()->admin()->create();
        config(['services.whatsapp.graph_version' => 'v99.0', 'services.whatsapp.webhook_version' => null]);

        $this->artisan('whatsapp:graph-version-check')
            ->expectsOutputToContain('غير موجود في جدول الإصدارات')
            ->expectsOutputToContain('WA_WEBHOOK_VERSION غير مضبوط')
            ->assertExitCode(0);

        $this->assertSame(2, $this->alerts($admin));
    }

    public function test_smoke_reads_our_number_on_the_target_version_and_sends_nothing(): void
    {
        $this->configureChannel();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => '106540352242922', 'display_phone_number' => '15550783881', 'quality_rating' => 'GREEN', 'verified_name' => 'Test',
        ])]);

        $this->artisan('whatsapp:graph-smoke', ['--target' => 'v26.0'])
            ->expectsOutputToContain('Graph v26.0: OK')
            ->expectsOutputToContain('quality_rating: GREEN')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_starts_with($r->url(), 'https://graph.facebook.com/v26.0/106540352242922?fields='));
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
        $this->assertSame('v25.0', config('services.whatsapp.graph_version'), 'الإصدار المضبوط أُعيد');
    }

    public function test_smoke_media_reports_host_and_hash_without_content(): void
    {
        $this->configureChannel();
        $conv = WaConversation::factory()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'msg_type' => 'audio', 'body' => 'نص سري', 'payload' => ['audio' => ['id' => '1037543291543636']]]);
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'lookaside.fbsbx.com')) {
                return Http::response('MEDIA-BYTES');
            }

            return Http::response(str_contains($r->url(), 'fields=')
                ? ['id' => '106540352242922']
                : ['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'audio/ogg', 'sha256' => hash('sha256', 'MEDIA-BYTES'), 'file_size' => 11]);
        });

        $this->artisan('whatsapp:graph-smoke', ['--media' => true])
            ->expectsOutputToContain('host=lookaside.fbsbx.com mime=audio/ogg size=11 sha256=مطابق')
            ->doesntExpectOutputToContain('نص سري')
            ->assertExitCode(0);
    }

    public function test_invalid_target_is_refused_without_requests(): void
    {
        Http::fake();

        $this->artisan('whatsapp:graph-smoke', ['--target' => 'latest'])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_settings_page_shows_the_versions_panel(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        config(['services.whatsapp.webhook_version' => 'v25.0']);

        $this->actingAs($owner)->get('/whatsapp/settings')->assertInertia(fn ($page) => $page
            ->where('graph.requests.version', 'v25.0')
            ->where('graph.requests.sunset', '2028-07-29')
            ->where('graph.webhook.level', 'ok')
            ->where('graph.appsecret_proof', false)
            ->where('graph.media_host_mode', 'log'));
    }
}
