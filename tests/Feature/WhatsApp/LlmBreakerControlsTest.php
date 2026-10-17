<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\LlmCircuitBreaker as Breaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * التحكم في القاطع والاحتياطي (E12.6/E12.9): الفتح والإغلاق اليدويان لمن يملك الإعدادات وحده وبسجل،
 * والصندوق يرى الحالة والأوقات بلا سبب تقني، والاحتياطي يُستعمل حين يُحجب الأساسي، والخصوصية تسمّيه.
 */
class LlmBreakerControlsTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const FALLBACK_KEY = 'sk-ant-api03-fallback-test-key-000000';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel(['breaker_mode' => 'on']);
        $this->owner = User::factory()->withPermissions(['whatsapp.settings', 'whatsapp.view', 'whatsapp.view_all'], 'owner')->create();
    }

    private function withFallback(): void
    {
        WaBotSetting::current()->forceFill(['fallback_provider' => 'anthropic', 'fallback_model' => 'claude-test', 'fallback_api_key' => self::FALLBACK_KEY])->save();
    }

    public function test_manual_open_and_close_need_the_settings_permission_and_are_audited(): void
    {
        $sales = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply'], 'sales')->create();
        $this->actingAs($sales)->postJson('/api/whatsapp/settings/llm-breaker/open', ['minutes' => 15])->assertForbidden();
        $this->actingAs($sales)->postJson('/api/whatsapp/settings/llm-breaker/close')->assertForbidden();

        $this->actingAs($this->owner)->postJson('/api/whatsapp/settings/llm-breaker/open', ['minutes' => 2])->assertJsonValidationErrors('minutes');
        $this->actingAs($this->owner)->postJson('/api/whatsapp/settings/llm-breaker/open', ['minutes' => 15])
            ->assertOk()->assertJsonPath('breaker.primary.state', 'open')->assertJsonPath('breaker.incidents.0.kind', 'manual');
        $this->actingAs($this->owner)->postJson('/api/whatsapp/settings/llm-breaker/close')
            ->assertOk()->assertJsonPath('breaker.primary.state', 'closed');

        $this->assertSame(['open', 'close'], AuditLog::where('action', 'wa.breaker_manual')->orderBy('id')->get()->pluck('changes.action')->all());
    }

    public function test_the_inbox_sees_state_and_times_only(): void
    {
        $viewer = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all'], 'viewer')->create();
        Breaker::forceOpen('google', 15, $this->owner->id);

        $llm = $this->actingAs($viewer)->getJson('/api/whatsapp/conversations')->assertOk()->json('llm');

        $this->assertSame(['state', 'opened_at', 'open_until'], array_keys($llm));
        $this->assertSame('open', $llm['state']);
        $this->actingAs($viewer)->get('/whatsapp/inbox')->assertInertia(fn ($page) => $page->where('llm.state', 'open')->missing('llm.reason'));
    }

    public function test_the_settings_page_shows_the_breaker_and_the_fallback_key_only_as_present(): void
    {
        $this->withFallback();
        Breaker::forceOpen('google', 15, $this->owner->id);

        $res = $this->actingAs($this->owner)->get('/whatsapp/settings')->assertInertia(fn ($page) => $page
            ->where('breaker.primary.state', 'open')->where('breaker.fallback.ready', true)
            ->where('secrets.fallback_api_key.has', true)->where('settings.breaker_mode', 'on'));

        $this->assertStringNotContainsString(self::FALLBACK_KEY, $res->getContent());
    }

    public function test_an_open_primary_uses_a_ready_fallback_with_its_own_key_and_model(): void
    {
        $this->withFallback();
        $this->fakeGraph();
        $this->fakeAnthropic('أهلاً بك من الاحتياطي');
        Breaker::forceOpen('google', 15, $this->owner->id);
        $conv = WaConversation::factory()->windowOpen()->create(['bot_enabled' => true]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'مرحبا']);

        BotEngine::reply($conv);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com') && $r->header('x-api-key')[0] === self::FALLBACK_KEY && $r['model'] === 'claude-test');
        $this->assertSame('claude-test', WaBotUsage::sole()->model);
        $this->assertSame('أهلاً بك من الاحتياطي', WaMessage::where('direction', 'out')->sole()->body);
    }

    public function test_the_fallback_has_its_own_breaker(): void
    {
        $this->withFallback();
        $this->fakeGraph();
        Breaker::forceOpen('google', 15, $this->owner->id);
        Breaker::forceOpen('anthropic', 15, $this->owner->id);
        $conv = WaConversation::factory()->windowOpen()->create(['bot_enabled' => true]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'مرحبا']);

        $this->assertSame('llm_outage', BotEngine::reply($conv)['reason']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'anthropic') || str_contains($r->url(), 'generativelanguage'));
    }

    public function test_a_fallback_identical_to_the_primary_is_refused(): void
    {
        $s = WaBotSetting::current();

        $this->actingAs($this->owner)->patchJson('/whatsapp/settings', ['version' => (int) $s->settings_version, 'confirm' => true,
            'changes' => ['fallback_provider' => 'google', 'fallback_model' => $s->model]])->assertJsonValidationErrors('changes.fallback_model');
    }

    public function test_the_privacy_page_names_the_active_providers(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Google Gemini')->assertDontSee('Anthropic');

        $this->withFallback();
        $this->get('/privacy')->assertOk()->assertSee('Anthropic Claude');
    }
}
