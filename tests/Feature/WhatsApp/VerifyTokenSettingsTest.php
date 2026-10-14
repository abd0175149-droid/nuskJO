<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaBotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رمز التحقق من الويبهوك (E10): يُولَّد عشوائياً ويُعرض مرة واحدة، والقاعدة تحفظ هاشه وحده،
 * والمصافحة تقارن الهاش، والبيئة تتقدّم. لا كتابة يدوية للرمز بعد الآن.
 */
class VerifyTokenSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        WaBotSetting::current()->update(['wa_verify_token' => 'legacy-plain-token-1234']);
    }

    private function handshake(string $token)
    {
        return $this->get('/api/whatsapp/webhook?'.http_build_query(['hub_mode' => 'subscribe', 'hub_verify_token' => $token, 'hub_challenge' => '123']));
    }

    public function test_a_generated_token_is_shown_once_and_only_its_hash_is_kept(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/whatsapp/settings/verify-token', ['reason' => 'routine'])->assertOk();

        $token = $response->json('token');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $s = WaBotSetting::current()->fresh();
        $this->assertNull($s->wa_verify_token, 'النص الصريح يُمسح');
        $this->assertSame(hash('sha256', $token), $s->wa_verify_token_hash);
        $this->assertNotNull($s->wa_verify_token_rotated_at);

        $page = $this->actingAs($this->owner)->getJson('/api/whatsapp/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString($token, $page);
        $this->assertStringNotContainsString($s->wa_verify_token_hash, $page);
        $audit = AuditLog::where('action', AuditAction::WaVerifyRotate->value)->sole();
        $this->assertSame('routine', $audit->changes['reason']);
        $this->assertStringNotContainsString($token, json_encode($audit->toArray()));
        $this->assertStringNotContainsString($s->wa_verify_token_hash, json_encode($audit->toArray()));
    }

    public function test_the_handshake_compares_hashes_and_records_the_last_success(): void
    {
        // قبل التوليد: النص الصريح القديم ما زال يعمل (للتراجع حتى finalize)
        $this->handshake('legacy-plain-token-1234')->assertOk()->assertSee('123');

        $token = $this->actingAs($this->owner)->postJson('/api/whatsapp/settings/verify-token', ['reason' => 'setup'])->json('token');
        $this->handshake('legacy-plain-token-1234')->assertForbidden();
        $this->handshake('wrong')->assertForbidden();
        $this->handshake($token)->assertOk()->assertSee('123');

        $this->assertNotNull($this->actingAs($this->owner)->getJson('/api/whatsapp/settings')->json('verify.last_handshake_at'));
    }

    public function test_an_environment_token_takes_precedence_and_cannot_be_rotated_here(): void
    {
        config(['services.whatsapp.verify_token' => 'env-token-0123456789']);

        $this->handshake('env-token-0123456789')->assertOk();
        $this->handshake('legacy-plain-token-1234')->assertForbidden();
        $this->actingAs($this->owner)->postJson('/api/whatsapp/settings/verify-token', ['reason' => 'routine'])->assertStatus(409);
        $this->assertSame('env', $this->actingAs($this->owner)->getJson('/api/whatsapp/settings')->json('verify.source'));
    }

    public function test_the_token_cannot_be_written_through_the_settings_patch(): void
    {
        $this->actingAs($this->owner)->patchJson('/whatsapp/settings', ['version' => 1, 'changes' => ['wa_verify_token' => str_repeat('a1', 16)]])
            ->assertUnprocessable();

        $this->assertSame('legacy-plain-token-1234', WaBotSetting::current()->fresh()->wa_verify_token);
    }
}
