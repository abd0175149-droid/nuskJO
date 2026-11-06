<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaBotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\PatchesWhatsAppSettings;
use Tests\TestCase;

/**
 * أسرار الإعدادات (E10؛ منذ OpenWA في 2026-11-06 مفتاحا مزوّد الذكاء وحدهما، ومفاتيح البوابة في البيئة وwa_channels):
 * تُعيَّن بسبب وتُمسح، وتُحفظ مشفّرة، ولا تعود للمتصفح بأي طريق، والبيئة تتقدّم، وسر لا يُفك لا يُسقط الصفحة.
 * ومصدر كل مفتاح ظاهر بلا قيمته.
 */
class SettingsSecretsTest extends TestCase
{
    use ConfiguresWhatsApp;
    use PatchesWhatsAppSettings;
    use RefreshDatabase;

    private const SEEDED = ['AIzaSeededKey000000000000000001', 'sk-ant-seeded-fallback-0000000001', 'seeded-channel-api-key-000001'];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.llm.google_key' => null, 'services.llm.anthropic_key' => null]);
        $this->owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        $this->configureChannel(['api_key' => self::SEEDED[0], 'fallback_api_key' => self::SEEDED[1]]);
        $this->testChannel()->forceFill(['api_key' => self::SEEDED[2]])->save();
    }

    private function secret(string $secret, string $value, string $reason = 'routine')
    {
        return $this->actingAs($this->owner)->postJson("/api/whatsapp/settings/secrets/{$secret}", ['value' => $value, 'reason' => $reason]);
    }

    public function test_setting_a_secret_stores_it_encrypted_and_answers_without_it(): void
    {
        $response = $this->secret('api_key', 'AIzaNewKeyValue000000000000002')->assertOk();

        $raw = DB::table('wa_bot_settings')->value('api_key');
        $this->assertNotSame('AIzaNewKeyValue000000000000002', $raw);
        $this->assertSame('AIzaNewKeyValue000000000000002', WaBotSetting::current()->fresh()->llmKey());
        $this->assertNotNull(WaBotSetting::current()->fresh()->api_key_rotated_at);
        $this->assertStringNotContainsString('AIzaNewKeyValue', $response->getContent());
        $this->assertSame(substr(hash('sha256', 'AIzaNewKeyValue000000000000002'), 0, 8), $response->json('fingerprint'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $audit = AuditLog::where('action', AuditAction::WaSecretSet->value)->sole();
        $this->assertStringNotContainsString('AIzaNewKeyValue', json_encode($audit->toArray()));
        $this->assertSame($response->json('fingerprint'), $audit->changes['credentials'][0]['fp']);
    }

    public function test_clearing_a_secret_and_the_environment_taking_precedence(): void
    {
        $this->actingAs($this->owner)->deleteJson('/api/whatsapp/settings/secrets/fallback_api_key', ['reason' => 'leak'])->assertOk();
        $this->assertNull(DB::table('wa_bot_settings')->value('fallback_api_key'));
        $this->assertSame(1, AuditLog::where('action', AuditAction::WaSecretClear->value)->count());

        config(['services.llm.google_key' => 'AIzaFromTheEnvironment0000000001']);
        $this->secret('api_key', 'AIzaAnotherKeyValue00000000001')->assertStatus(409);
        foreach (['wa_token', 'wa_app_secret', 'not_a_secret'] as $gone) {
            $this->actingAs($this->owner)->postJson("/api/whatsapp/settings/secrets/{$gone}", ['value' => str_repeat('x', 30), 'reason' => 'routine'])->assertNotFound();
        }
    }

    public function test_no_response_ever_carries_a_seeded_secret(): void
    {
        $responses = [
            $this->actingAs($this->owner)->get('/whatsapp/settings')->assertOk()->getContent(),
            $this->actingAs($this->owner)->getJson('/api/whatsapp/settings')->assertOk()->getContent(),
            json_encode(WaBotSetting::current()->fresh()->sources()),
            json_encode(WaBotSetting::current()->fresh()->toArray()),
            json_encode($this->testChannel()->fresh()->toArray()),
        ];
        Artisan::call('whatsapp:doctor', ['--offline' => true]);
        $responses[] = Artisan::output();

        foreach ($responses as $content) {
            foreach (self::SEEDED as $secret) {
                $this->assertStringNotContainsString($secret, $content);
            }
        }
    }

    public function test_a_failed_save_does_not_keep_a_secret_in_the_session(): void
    {
        $this->actingAs($this->owner)->from('/whatsapp/settings')
            ->post('/api/whatsapp/settings/secrets/api_key', ['value' => 'AIzaFlashedKeyValue0000000003', 'reason' => 'nope'])->assertSessionHasErrors('reason');

        $this->assertStringNotContainsString('AIzaFlashedKey', json_encode(session()->all()));
    }

    public function test_an_unreadable_key_shows_as_not_decryptable_without_a_500(): void
    {
        DB::table('wa_bot_settings')->update(['api_key' => 'not-a-valid-ciphertext']);

        $this->actingAs($this->owner)->getJson('/api/whatsapp/settings')->assertOk()
            ->assertJsonPath('secrets.api_key.decrypt_ok', false)
            ->assertJsonPath('secrets.api_key.has', false);
        $this->assertNull(WaBotSetting::current()->fresh()->llmKey());
    }

    public function test_sources_say_where_each_value_comes_from(): void
    {
        $sources = WaBotSetting::current()->fresh()->sources();
        $this->assertSame('db', $sources['api_key']['source']);
        $this->assertFalse($sources['api_key']['db_shadowed']);
        $this->assertTrue($sources['api_key']['decrypt_ok']);
        $this->assertArrayNotHasKey('wa_token', $sources);

        config(['services.llm.google_key' => 'AIzaFromTheEnvironment0000000001']);
        $sources = WaBotSetting::current()->fresh()->sources();
        $this->assertSame(['env', true], [$sources['api_key']['source'], $sources['api_key']['db_shadowed']]);

        // الإيقاف منطق OR
        $this->assertFalse(WaBotSetting::current()->fresh()->sendingSuspended());
        config(['services.whatsapp.suspended' => true]);
        $this->assertTrue(WaBotSetting::current()->fresh()->sendingSuspended());
        $this->assertSame('env', WaBotSetting::current()->fresh()->sources()['wa_suspended']['source']);
    }
}
