<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaBotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\PatchesWhatsAppSettings;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * أسرار القناة (E10): تُعيَّن بسبب وتُمسح، وتُحفظ مشفّرة، ولا تعود للمتصفح بأي طريق، والبيئة تتقدّم،
 * وسر لا يُفك لا يُسقط الويبهوك بـ 500. ومصدر كل مفتاح ظاهر بلا قيمته.
 */
class SettingsSecretsTest extends TestCase
{
    use ConfiguresWhatsApp;
    use PatchesWhatsAppSettings;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const SEEDED = ['EAAGseededtoken000000001', 'seededappsecret00000001', 'AIzaSeededKey000000000000000001'];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        $this->configureChannel(['wa_token' => self::SEEDED[0], 'wa_app_secret' => self::SEEDED[1], 'api_key' => self::SEEDED[2]]);
    }

    private function secret(string $secret, string $value, string $reason = 'routine')
    {
        return $this->actingAs($this->owner)->postJson("/api/whatsapp/settings/secrets/{$secret}", ['value' => $value, 'reason' => $reason]);
    }

    public function test_setting_a_secret_stores_it_encrypted_and_answers_without_it(): void
    {
        $response = $this->secret('wa_token', 'EAAGnewtokenvalue0000000002')->assertOk();

        $raw = DB::table('wa_bot_settings')->value('wa_token');
        $this->assertNotSame('EAAGnewtokenvalue0000000002', $raw);
        $this->assertSame('EAAGnewtokenvalue0000000002', WaBotSetting::current()->fresh()->token());
        $this->assertNotNull(WaBotSetting::current()->fresh()->wa_token_rotated_at);
        $this->assertStringNotContainsString('EAAGnewtokenvalue', $response->getContent());
        $this->assertSame(substr(hash('sha256', 'EAAGnewtokenvalue0000000002'), 0, 8), $response->json('fingerprint'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $audit = AuditLog::where('action', AuditAction::WaSecretSet->value)->sole();
        $this->assertStringNotContainsString('EAAGnewtokenvalue', json_encode($audit->toArray()));
        $this->assertSame($response->json('fingerprint'), $audit->changes['credentials'][0]['fp']);
    }

    public function test_clearing_a_secret_and_the_environment_taking_precedence(): void
    {
        $this->actingAs($this->owner)->deleteJson('/api/whatsapp/settings/secrets/api_key', ['reason' => 'leak'])->assertOk();
        $this->assertNull(DB::table('wa_bot_settings')->value('api_key'));
        $this->assertSame(1, AuditLog::where('action', AuditAction::WaSecretClear->value)->count());

        config(['services.whatsapp.app_secret' => 'env-app-secret-0000000001']);
        $this->secret('wa_app_secret', 'another-app-secret-00000001')->assertStatus(409);
        $this->actingAs($this->owner)->postJson('/api/whatsapp/settings/secrets/not_a_secret', ['value' => str_repeat('x', 30), 'reason' => 'routine'])->assertNotFound();
    }

    public function test_no_response_ever_carries_a_seeded_secret(): void
    {
        $responses = [
            $this->actingAs($this->owner)->get('/whatsapp/settings')->assertOk()->getContent(),
            $this->actingAs($this->owner)->getJson('/api/whatsapp/settings')->assertOk()->getContent(),
            json_encode(WaBotSetting::current()->fresh()->sources()),
            json_encode(WaBotSetting::current()->fresh()->toArray()),
        ];
        Artisan::call('whatsapp:doctor');
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
            ->put('/whatsapp/settings', ['wa_token' => 'EAAGflashedtoken0000000003', 'value' => 'EAAGflashedtoken0000000003']);
        $this->actingAs($this->owner)->from('/whatsapp/settings')
            ->post('/api/whatsapp/settings/secrets/wa_token', ['value' => 'EAAGflashedtoken0000000003', 'reason' => 'nope'])->assertSessionHasErrors('reason');

        $this->assertStringNotContainsString('EAAGflashedtoken', json_encode(session()->all()));
    }

    public function test_an_unreadable_app_secret_rejects_the_webhook_with_403_not_500(): void
    {
        Queue::fake();
        DB::table('wa_bot_settings')->update(['wa_app_secret' => 'not-a-valid-ciphertext']);

        $this->postMetaWebhook('text.json')->assertForbidden();

        $this->actingAs($this->owner)->getJson('/api/whatsapp/settings')->assertOk()
            ->assertJsonPath('secrets.wa_app_secret.decrypt_ok', false)
            ->assertJsonPath('secrets.wa_app_secret.has', false);
    }

    public function test_sources_say_where_each_value_comes_from(): void
    {
        $sources = WaBotSetting::current()->fresh()->sources();
        $this->assertSame('db', $sources['wa_token']['source']);
        $this->assertFalse($sources['wa_token']['db_shadowed']);
        $this->assertTrue($sources['wa_token']['decrypt_ok']);
        $this->assertSame('unset', $sources['wa_app_id']['source']);

        config(['services.whatsapp.token' => 'env-token-value-000000001', 'services.whatsapp.phone_number_id' => '']);
        $sources = WaBotSetting::current()->fresh()->sources();
        $this->assertSame(['env', 'WA_TOKEN', true], [$sources['wa_token']['source'], $sources['wa_token']['env_var'], $sources['wa_token']['db_shadowed']]);
        $this->assertSame('db', $sources['wa_phone_number_id']['source'], 'متغير فارغ = غير مضبوط');

        // الإيقاف منطق OR
        $this->assertFalse(WaBotSetting::current()->fresh()->sendingSuspended());
        config(['services.whatsapp.suspended' => true]);
        $this->assertTrue(WaBotSetting::current()->fresh()->sendingSuspended());
        $this->assertSame('env', WaBotSetting::current()->fresh()->sources()['wa_suspended']['source']);
    }
}
