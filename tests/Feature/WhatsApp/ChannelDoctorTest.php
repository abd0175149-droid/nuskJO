<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Services\WhatsApp\ChannelDoctor;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/** whatsapp:doctor: يرصد الإعداد الخاطئ برسالة ونصيحة، ولا يطبع أي قيمة سرية. */
class ChannelDoctorTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['trustedproxy.proxies' => '127.0.0.1']);
    }

    private function codes(): array
    {
        return array_column(ChannelDoctor::checks(), 'code');
    }

    public function test_a_healthy_setup_reports_nothing(): void
    {
        $this->configureChannel();

        $this->assertSame([], ChannelDoctor::checks());
        $this->artisan('whatsapp:doctor', ['--strict' => true, '--offline' => true])->expectsOutputToContain('لا ملاحظات')->assertExitCode(0);
    }

    public function test_the_doctor_lists_sources_and_warns_about_shadowing_and_old_keys_without_values(): void
    {
        $this->configureChannel(['provider' => 'google']);
        config(['services.llm.google_key' => 'AIza-env-key-value-0000000001']);
        // سر مشفّر بمفتاح سابق ما زال في APP_PREVIOUS_KEYS
        $old = 'base64:'.base64_encode(random_bytes(32));
        $raw = (new Encrypter(base64_decode(substr($old, 7)), 'AES-256-CBC'))->encryptString('old-key-fallback-00001');
        DB::table('wa_bot_settings')->update(['fallback_api_key' => $raw]);
        config(['app.previous_keys' => [$old]]);
        $this->app->forgetInstance('encrypter');
        \Illuminate\Support\Facades\Crypt::clearResolvedInstance('encrypter');

        $this->assertSame(1, \Illuminate\Support\Facades\Artisan::call('whatsapp:doctor', ['--strict' => true, '--offline' => true]));
        $output = \Illuminate\Support\Facades\Artisan::output();

        foreach (['api_key', 'ENV_SHADOWS_DB', 'SECRET_ON_PREVIOUS_KEY'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
        foreach (['old-key-fallback', 'AIza-env-key-value', 'test-llm-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_secrets_saved_under_another_app_key_are_reported_without_their_value(): void
    {
        $this->configureChannel();
        $foreign = fn (string $v) => (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString($v);
        DB::table('wa_bot_settings')->update(['api_key' => $foreign('AIza-foreign-llm-key')]);
        DB::table('wa_channels')->update(['api_key' => $foreign('foreign-channel-key')]);

        $checks = ChannelDoctor::checks();

        $this->assertContains('SECRET_UNREADABLE', array_column($checks, 'code'));
        $this->assertContains('CHANNEL_KEY_UNREADABLE', array_column($checks, 'code'));
        $this->assertStringNotContainsString('foreign', json_encode($checks, JSON_UNESCAPED_UNICODE));
    }

    public function test_weak_settings_are_reported(): void
    {
        $this->configureChannel();
        User::factory()->create(['email' => 'admin@nusuk-jo.com', 'password' => Hash::make('password')]);
        config(['trustedproxy.proxies' => '*', 'logging.channels.daily.level' => 'error']);

        $codes = $this->codes();

        foreach (['DEFAULT_ADMIN_PASSWORD', 'TRUST_ALL_PROXIES', 'LOG_LEVEL_TOO_STRICT'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->artisan('whatsapp:doctor', ['--offline' => true])->assertExitCode(0);
        $this->artisan('whatsapp:doctor', ['--strict' => true, '--offline' => true])->assertExitCode(1);
    }

    public function test_a_missing_gateway_key_in_production_is_an_error(): void
    {
        $this->configureChannel();
        config(['whatsapp.openwa.admin_key' => '']);
        $this->app['env'] = 'production';

        $this->assertContains('OPENWA_ADMIN_KEY_MISSING', $this->codes());
    }

    public function test_settings_page_carries_the_health_list(): void
    {
        $this->configureChannel();
        config(['trustedproxy.proxies' => '*']);
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $this->actingAs($owner)->get('/whatsapp/settings')->assertInertia(fn ($page) => $page
            ->where('health.0.code', 'TRUST_ALL_PROXIES'));
    }
}
