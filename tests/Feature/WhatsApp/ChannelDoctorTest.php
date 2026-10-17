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
        // رمز تحقق مولَّد (هاشه وحده محفوظ)، لا مكتوب يدوياً
        $this->configureChannel(['wa_verify_token' => null, 'wa_verify_token_hash' => hash('sha256', str_repeat('v', 64))]);

        $this->assertSame([], ChannelDoctor::checks());
        $this->artisan('whatsapp:doctor', ['--strict' => true, '--offline' => true])->expectsOutputToContain('لا ملاحظات')->assertExitCode(0);
    }

    public function test_the_doctor_lists_sources_and_warns_about_shadowing_and_old_keys_without_values(): void
    {
        $this->configureChannel(['wa_verify_token' => 'typed-by-hand-token-0001']);
        config(['services.whatsapp.token' => 'env-token-value-0000000001']);
        // سر مشفّر بمفتاح سابق ما زال في APP_PREVIOUS_KEYS
        $old = 'base64:'.base64_encode(random_bytes(32));
        $raw = (new Encrypter(base64_decode(substr($old, 7)), 'AES-256-CBC'))->encryptString('old-key-app-secret-00001');
        DB::table('wa_bot_settings')->update(['wa_app_secret' => $raw]);
        config(['app.previous_keys' => [$old]]);
        $this->app->forgetInstance('encrypter');
        \Illuminate\Support\Facades\Crypt::clearResolvedInstance('encrypter');

        $this->assertSame(1, \Illuminate\Support\Facades\Artisan::call('whatsapp:doctor', ['--strict' => true]));
        $output = \Illuminate\Support\Facades\Artisan::output();

        foreach (['wa_phone_number_id', 'WA_TOKEN', 'ENV_SHADOWS_DB', 'SECRET_ON_PREVIOUS_KEY', 'VERIFY_TOKEN_PLAIN'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
        foreach (['old-key-app-secret', 'env-token-value', 'typed-by-hand-token'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_secret_saved_under_another_app_key_is_reported_without_its_value(): void
    {
        $this->configureChannel(['wa_verify_token' => str_repeat('v', 32)]);
        $foreign = (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString('EAAG-secret-token');
        DB::table('wa_bot_settings')->update(['wa_token' => $foreign]);

        $checks = ChannelDoctor::checks();

        $this->assertContains('SECRET_UNREADABLE', array_column($checks, 'code'));
        $this->assertStringNotContainsString('EAAG', json_encode($checks, JSON_UNESCAPED_UNICODE));
    }

    public function test_weak_settings_are_reported(): void
    {
        $this->configureChannel(['wa_app_secret' => null, 'wa_verify_token' => 'short']);
        User::factory()->create(['email' => 'admin@nusuk-jo.com', 'password' => Hash::make('password')]);
        Cache::put('wa:last_auth_error_at', now()->subHour()->toIso8601String());
        config(['trustedproxy.proxies' => '*', 'logging.channels.daily.level' => 'error']);

        $codes = $this->codes();

        foreach (['NO_APP_SECRET', 'SHORT_VERIFY_TOKEN', 'DEFAULT_ADMIN_PASSWORD', 'META_TOKEN_REJECTED', 'TRUST_ALL_PROXIES', 'LOG_LEVEL_TOO_STRICT'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->artisan('whatsapp:doctor')->assertExitCode(0);
        $this->artisan('whatsapp:doctor', ['--strict' => true])->assertExitCode(1);
    }

    public function test_settings_page_carries_the_health_list(): void
    {
        $this->configureChannel(['wa_verify_token' => 'short']);
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $this->actingAs($owner)->get('/whatsapp/settings')->assertInertia(fn ($page) => $page
            ->where('health.0.code', 'SHORT_VERIFY_TOKEN'));
    }
}
