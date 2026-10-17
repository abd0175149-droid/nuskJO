<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\MessageTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\PatchesWhatsAppSettings;
use Tests\TestCase;

/**
 * تأكيد الطبقة المدفوعة لمزوّد الذكاء (E29.8، شرط الترجمة): للمدير، ومدقَّق صارماً، ومربوط بالمزوّد والمفتاح اللذين
 * أُكّد عليهما: تغيير المزوّد أو المفتاح المحفوظ يمسحه، ومفتاح جديد في البيئة يُسقطه حتى يُؤكَّد من جديد.
 */
class LlmTermsConfirmationTest extends TestCase
{
    use ConfiguresWhatsApp;
    use PatchesWhatsAppSettings;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->owner = User::factory()->withPermissions(['whatsapp.settings'], 'wa-owner')->create();
    }

    private function confirm(bool $on = true, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->postJson('/api/whatsapp/settings/llm-terms', ['confirm' => $on]);
    }

    public function test_the_owner_confirms_and_revokes_with_a_strict_audit_row(): void
    {
        $this->confirm(true, User::factory()->withPermissions(['whatsapp.view'], 'viewer')->create())->assertForbidden();

        $this->confirm()->assertOk()->assertJsonPath('llmTerms.valid', true)->assertJsonPath('llmTerms.by_name', $this->owner->name)
            ->assertJsonPath('llmTerms.required', true)->assertJsonPath('llmTerms.provider', 'Google Gemini');
        $this->assertTrue(MessageTranslator::termsConfirmed(WaBotSetting::current()));
        $audit = AuditLog::where('action', 'wa.llm_terms_confirm')->sole();
        $this->assertSame('warn', $audit->severity);
        $this->assertTrue($audit->changes['confirm']);

        $this->confirm(false)->assertOk()->assertJsonPath('llmTerms.confirmed', false);
        $this->assertFalse(MessageTranslator::termsConfirmed(WaBotSetting::current()));
        $this->assertSame(2, AuditLog::where('action', 'wa.llm_terms_confirm')->count());

        $this->actingAs($this->owner)->get('/whatsapp/settings')->assertOk()->assertInertia(fn ($page) => $page->where('llmTerms.valid', false));
    }

    public function test_changing_the_provider_or_the_key_clears_it_and_a_new_env_key_voids_it(): void
    {
        $this->confirm()->assertOk();
        $this->actingAs($this->owner)->postJson('/api/whatsapp/settings/secrets/api_key', ['value' => 'a-brand-new-provider-key-0001', 'reason' => 'routine'])->assertOk();
        $this->assertNull(WaBotSetting::current()->llm_terms_confirmed_at);

        $this->confirm()->assertOk();
        $this->patchSettings($this->owner, ['provider' => 'anthropic'], confirm: true)->assertOk();
        $s = WaBotSetting::current();
        $this->assertNull($s->llm_terms_confirmed_at);
        $this->assertTrue(MessageTranslator::termsConfirmed($s), 'anthropic لا يحتاج تأكيداً');

        $this->patchSettings($this->owner, ['provider' => 'google'], confirm: true)->assertOk();
        $this->confirm()->assertOk();
        config(['services.llm.google_key' => 'a-different-key-from-the-environment']);
        $this->confirm(false);
        $this->confirm()->assertOk()->assertJsonPath('llmTerms.valid', true);
        config(['services.llm.google_key' => 'yet-another-env-key']);
        $state = MessageTranslator::termsState(WaBotSetting::current());
        $this->assertTrue($state['confirmed']);
        $this->assertTrue($state['stale']);
        $this->assertFalse($state['valid']);
    }

    public function test_confirming_without_a_key_is_refused(): void
    {
        $this->configureChannel(['api_key' => null]);
        $this->confirm()->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->assertSame(0, AuditLog::where('action', 'wa.llm_terms_confirm')->count());
    }
}
