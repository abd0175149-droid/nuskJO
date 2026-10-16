<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Services\WhatsApp\ChannelDoctor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\FakesMetaReads;
use Tests\TestCase;

/**
 * whatsapp:doctor الموسَّع (E23): تعارضات البيئة، وقراءات ميتا بميزانية زمنية كلية، و--offline و--store، والزر.
 * لا يطبع أي قيمة سرية، ولا يمنع الإقلاع أبداً.
 */
class DoctorTest extends TestCase
{
    use ConfiguresWhatsApp;
    use FakesMetaReads;
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

    public function test_output_never_contains_a_secret_value(): void
    {
        config(['app.url' => self::OUR_URL]);
        $this->configureChannel(['wa_app_id' => self::OUR_APP_ID, 'wa_token' => 'EAAG-doctor-secret-token', 'wa_app_secret' => 'app-secret-doctor-0001', 'api_key' => 'AIza-doctor-llm-key']);
        $this->fakeMetaReads();

        $this->assertSame(0, Artisan::call('whatsapp:doctor', ['--budget' => 20]));
        $output = Artisan::output().json_encode(ChannelDoctor::run(20), JSON_UNESCAPED_UNICODE);

        foreach (['EAAG-doctor-secret-token', 'app-secret-doctor-0001', 'AIza-doctor-llm-key', (string) config('app.key')] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        $this->assertStringContainsString('META_TOKEN', $output);
        $this->assertStringContainsString('WEBHOOK_APP', $output);
    }

    public function test_remote_reads_report_a_healthy_channel(): void
    {
        config(['app.url' => self::OUR_URL, 'services.whatsapp.waba_id' => '102290129340398']);
        $this->configureChannel(['wa_app_id' => self::OUR_APP_ID]);
        $this->fakeMetaReads();

        $remote = collect(ChannelDoctor::remote(20))->keyBy('code');

        foreach (['META_TOKEN', 'META_NUMBER', 'WEBHOOK_APP', 'WEBHOOK_WABA'] as $code) {
            $this->assertSame('ok', $remote[$code]['level'], $code);
        }
        $this->assertSame(['token', 'node', 'app', 'waba'], $this->metaCalls);
        $this->assertSame([], array_filter($this->metaRequests, fn ($r) => $r->method() !== 'GET'));
    }

    public function test_remote_reads_find_a_foreign_callback_and_an_override(): void
    {
        config(['app.url' => self::OUR_URL, 'services.whatsapp.waba_id' => '102290129340398']);
        $this->configureChannel(['wa_app_id' => self::OUR_APP_ID]);
        $this->fakeMetaReads(['node' => 'phone_node_override', 'app' => 'app_subscriptions_wrong_url']);

        $codes = array_column(array_filter(ChannelDoctor::remote(20), fn ($c) => $c['level'] === 'error'), 'code');

        $this->assertContains('WEBHOOK_CALLBACK', $codes);
        $this->assertContains('WEBHOOK_OVERRIDE', $codes);
    }

    public function test_the_budget_skips_what_time_does_not_allow(): void
    {
        $this->configureChannel(['wa_app_id' => self::OUR_APP_ID]);
        $this->freezeTime();
        // كل نداء «يستغرق» 4 ثوانٍ على ساعة الاختبار
        $this->fakeMetaReads(array_fill_keys(['token', 'node', 'app', 'waba'], function () {
            $this->travel(4)->seconds();

            return Http::response(['error' => ['code' => 2, 'message' => 'slow']], 500);
        }));
        $started = microtime(true);

        $this->assertSame(0, Artisan::call('whatsapp:doctor', ['--budget' => 5]));

        $this->assertLessThan(3, microtime(true) - $started);
        $this->assertSame(['token', 'node'], $this->metaCalls);
        $this->assertStringContainsString('لم يُفحص — الوقت', Artisan::output());
    }

    public function test_offline_makes_no_call(): void
    {
        $this->configureChannel(['wa_app_id' => self::OUR_APP_ID]);
        $this->fakeMetaReads();

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
