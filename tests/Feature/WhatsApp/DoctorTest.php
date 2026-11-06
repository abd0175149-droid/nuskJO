<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Services\WhatsApp\ChannelDoctor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * whatsapp:doctor الموسَّع (E23): تعارضات البيئة، وقراءات البوابة وكل رقم بميزانية زمنية كلية (منذ OpenWA)، و--offline و--store، والزر.
 * لا يطبع أي قيمة سرية، ولا يمنع الإقلاع أبداً.
 */
class DoctorTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ChannelDoctor::$sqliteVersion = null;
        parent::tearDown();
    }

    private function local(string $code): ?array
    {
        return collect(ChannelDoctor::local())->firstWhere('code', $code);
    }

    public function test_a_retry_after_shorter_than_the_longest_job_is_an_error(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);

        $item = $this->local('RETRY_AFTER');

        $this->assertSame('error', $item['level']);
        $this->assertStringContainsString('ProcessWhatsAppReply', $item['message']);

        config(['queue.connections.database.retry_after' => 240]);
        $this->assertSame('ok', $this->local('RETRY_AFTER')['level']);
    }

    public function test_a_strict_log_level_warns(): void
    {
        config(['logging.channels.daily.level' => 'error']);

        $this->assertContains('LOG_LEVEL_TOO_STRICT', array_column(ChannelDoctor::run(5, offline: true)['checks'], 'code'));
    }

    public function test_a_sync_queue_in_production_is_an_error(): void
    {
        config(['queue.default' => 'sync']);
        $this->app['env'] = 'production';

        $this->assertSame('error', $this->local('QUEUE_SYNC')['level']);
    }

    public function test_an_old_sqlite_is_an_error(): void
    {
        ChannelDoctor::$sqliteVersion = fn () => '3.30.1';
        $this->assertSame('error', $this->local('SQLITE_VERSION')['level']);

        ChannelDoctor::$sqliteVersion = fn () => '3.45.1';
        $this->assertSame('ok', $this->local('SQLITE_VERSION')['level']);
    }

    public function test_the_conversation_lock_covers_the_reply_timeout(): void
    {
        $this->assertSame('ok', $this->local('LOCK_TTL')['level']);
    }

    /** بوابة وهمية: /health بإصدار، وwebhook كل جلسة إلى رابط رقمها بكل الأحداث، إلا ما يغلبه $hooks (الجلسة => الرد) */
    private function fakeDoctorGateway(array $hooks = [], ?\Closure $each = null): void
    {
        $routes = ['nuskjo-openwa:2785/api/health' => fn () => ($each ? $each() : null) ?? Http::response(['status' => 'ok', 'version' => '0.24.0'])];
        foreach (\App\Models\WaChannel::all() as $ch) {
            $routes["nuskjo-openwa:2785/api/sessions/{$ch->openwa_session_id}/webhooks"] = fn () => ($each ? $each() : null)
                ?? ($hooks[$ch->openwa_session_id] ?? Http::response(['data' => [['url' => $ch->webhookUrl(), 'events' => config('whatsapp.openwa.events'), 'active' => true]]]));
        }
        $this->fakeGateway($routes);
    }

    public function test_output_never_contains_a_secret_value(): void
    {
        $this->configureChannel(['api_key' => 'AIza-doctor-llm-key']);
        $this->testChannel()->forceFill(['api_key' => 'doctor-channel-key-0001', 'webhook_secret' => 'doctor-hook-secret-0001'])->save();
        $this->fakeDoctorGateway();

        $this->assertSame(0, Artisan::call('whatsapp:doctor', ['--budget' => 20]));
        $output = Artisan::output().json_encode(ChannelDoctor::run(20), JSON_UNESCAPED_UNICODE);

        foreach (['AIza-doctor-llm-key', 'doctor-channel-key-0001', 'doctor-hook-secret-0001', 'admin-test-key', 'sess-1', (string) config('app.key')] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        $this->assertStringContainsString('GATEWAY', $output);
        $this->assertStringContainsString('CHANNEL_WEBHOOK', $output);
    }

    public function test_remote_reads_report_a_healthy_gateway_and_number(): void
    {
        $this->configureChannel();
        $this->fakeDoctorGateway();

        $remote = collect(ChannelDoctor::remote(20))->keyBy('code');

        foreach (['GATEWAY', 'CHANNEL_READY', 'CHANNEL_WEBHOOK'] as $code) {
            $this->assertSame('ok', $remote[$code]['level'], $code);
        }
        $this->assertStringContainsString('0.24.0', $remote['GATEWAY']['message']);
        $this->assertSame([], Http::recorded(fn ($request) => $request->method() !== 'GET')->all());
    }

    public function test_remote_reads_find_a_missing_webhook_and_a_disconnected_number(): void
    {
        $this->configureChannel();
        $this->makeChannel(['status' => 'disconnected']);
        $this->fakeDoctorGateway(['sess-1' => Http::response(['data' => [['url' => 'http://elsewhere.test/hook', 'events' => ['message.received']]]])]);

        $errors = array_values(array_filter(ChannelDoctor::remote(20), fn ($c) => $c['level'] === 'error'));

        $this->assertSame(['CHANNEL_WEBHOOK', 'CHANNEL_READY'], array_column($errors, 'code'));
    }

    public function test_an_unreachable_gateway_is_one_error_and_no_number_is_read(): void
    {
        $this->configureChannel();
        $this->fakeDoctorGateway(each: fn () => Http::response(['message' => 'down'], 503));

        $remote = ChannelDoctor::remote(20);

        $this->assertSame([['GATEWAY', 'error']], array_map(fn ($c) => [$c['code'], $c['level']], $remote));
    }

    public function test_the_budget_skips_what_time_does_not_allow(): void
    {
        $this->configureChannel();
        $this->makeChannel();
        $this->freezeTime();
        // كل نداء «يستغرق» 4 ثوانٍ على ساعة الاختبار
        $this->fakeDoctorGateway(each: function () {
            $this->travel(4)->seconds();

            return null;
        });
        $started = microtime(true);

        $this->assertSame(0, Artisan::call('whatsapp:doctor', ['--budget' => 5]));

        $this->assertLessThan(3, microtime(true) - $started);
        $this->assertStringContainsString('لم يُفحص — الوقت', Artisan::output());
    }

    public function test_offline_makes_no_call(): void
    {
        $this->configureChannel();
        $this->fakeDoctorGateway();

        $this->assertSame(0, Artisan::call('whatsapp:doctor', ['--offline' => true]));

        Http::assertNothingSent();
    }

    public function test_store_keeps_the_result_and_alerts_once_on_a_new_error(): void
    {
        config(['features.env.channel_monitor' => true, 'queue.default' => 'database', 'queue.connections.database.retry_after' => 60]);
        $admin = User::factory()->admin()->create();

        Artisan::call('whatsapp:doctor', ['--offline' => true, '--store' => true]);
        Artisan::call('whatsapp:doctor', ['--offline' => true, '--store' => true]);

        $last = ChannelDoctor::last();
        $this->assertNotNull($last);
        $this->assertGreaterThan(0, $last['counts']['error']);
        $this->assertSame(['⛔ فحص الإعدادات وجد خطأً جديداً'], Notification::where('user_id', $admin->id)->pluck('title')->all());
        $this->assertStringContainsString('RETRY_AFTER', (string) Notification::where('user_id', $admin->id)->value('body'));
    }

    public function test_store_does_not_alert_while_the_flag_is_off(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 60]);
        User::factory()->admin()->create();

        Artisan::call('whatsapp:doctor', ['--offline' => true, '--store' => true]);

        $this->assertNotNull(ChannelDoctor::last());
        $this->assertSame(0, Notification::count());
    }

    public function test_the_entrypoint_runs_the_doctor_with_a_budget_and_never_blocks_boot(): void
    {
        $script = (string) file_get_contents(base_path('docker/entrypoint.sh'));
        $this->assertMatchesRegularExpression('/^as_www "php artisan whatsapp:doctor --no-ansi --budget=5" \|\| true$/m', $script);
        $this->assertStringContainsString('set -e', $script);

        // السطر نفسه بأمر يفشل: set -e لا يوقف السكربت بفضل || true
        $out = [];
        exec('bash -c '.escapeshellarg('set -e; as_www() { sh -c "$*"; }; as_www "exit 3" || true; echo after'), $out, $code);
        $this->assertSame(0, $code);
        $this->assertSame(['after'], $out);
    }

    public function test_the_button_needs_the_settings_permission_and_is_throttled(): void
    {
        $staff = User::factory()->withPermissions(['whatsapp.view'], 'staff')->create();
        $this->actingAs($staff)->postJson('/api/whatsapp/doctor')->assertForbidden();

        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        $res = $this->actingAs($owner)->postJson('/api/whatsapp/doctor')->assertOk();
        $this->assertArrayHasKey('checks', $res->json('doctor'));
        $this->assertNotNull(ChannelDoctor::last());

        $this->actingAs($owner)->postJson('/api/whatsapp/doctor')->assertOk();
        $this->actingAs($owner)->postJson('/api/whatsapp/doctor')->assertOk();
        $this->actingAs($owner)->postJson('/api/whatsapp/doctor')->assertStatus(429);
    }

    public function test_the_settings_page_carries_the_last_full_run(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        ChannelDoctor::store(ChannelDoctor::run(5, offline: true), alert: false);

        $this->actingAs($owner)->get('/whatsapp/settings')->assertInertia(fn ($page) => $page->has('doctor.checks')->has('doctor.counts'));
    }
}
