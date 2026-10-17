<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

/**
 * تشغيل البوت في محادثة أو إيقافه بقيمة صريحة (E21 T4): النقرتان بالقيمة نفسها تعطيان النتيجة نفسها، وتبويب قديم
 * بلا قيمة يقلبها مع سطر في السجل لإصدار واحد. الصلاحية وسياج المحادثة كما هما.
 */
class ToggleBotExplicitTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use CapturesLogs;
    use RefreshDatabase;

    private User $agent;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.extended' => true]);
        $this->agent = $this->staffWith('desk', ['whatsapp.view', 'whatsapp.bot_toggle', 'whatsapp.view_all']);
        $this->conv = $this->conversationTagged([], ['bot_enabled' => true, 'needs_attention' => true, 'bot_paused_until' => now()->addHour()]);
    }

    private function toggle(array $body = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->agent)->postJson("/api/whatsapp/conversations/{$this->conv->id}/toggle-bot", $body);
    }

    public function test_the_same_value_twice_gives_the_same_result(): void
    {
        $this->toggle(['enabled' => false])->assertOk()->assertExactJson(['bot_enabled' => false]);
        $this->toggle(['enabled' => false])->assertOk()->assertExactJson(['bot_enabled' => false]);

        $this->conv->refresh();
        $this->assertFalse($this->conv->bot_enabled);
        $this->assertFalse($this->conv->needs_attention);
        $this->assertNull($this->conv->bot_paused_until);
        $this->assertSame([false, false], AuditLog::where('action', AuditAction::WaBotToggle->value)->orderBy('id')->get()->map(fn ($r) => $r->changes['to'])->all());

        $this->toggle(['enabled' => true])->assertOk()->assertExactJson(['bot_enabled' => true]);
        $this->toggle(['enabled' => true])->assertOk()->assertExactJson(['bot_enabled' => true]);
    }

    public function test_an_old_tab_without_a_value_still_flips_and_is_logged(): void
    {
        $this->captureLogs();

        $this->toggle()->assertOk()->assertExactJson(['bot_enabled' => false]);
        $this->toggle()->assertOk()->assertExactJson(['bot_enabled' => true]);

        $this->assertLogged('info', 'wa.toggle_bot.legacy', ['conv_id' => $this->conv->id]);
    }

    public function test_the_value_must_be_a_boolean(): void
    {
        $this->toggle(['enabled' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('enabled');
        $this->assertTrue($this->conv->fresh()->bot_enabled);
    }

    public function test_without_the_permission_it_is_forbidden(): void
    {
        $viewer = $this->staffWith('viewer', ['whatsapp.view', 'whatsapp.view_all']);

        $this->toggle(['enabled' => false], $viewer)->assertForbidden();
        $this->assertTrue($this->conv->fresh()->bot_enabled);
    }

    public function test_a_conversation_outside_the_fence_is_not_found(): void
    {
        $narrow = $this->staffWith('narrow', ['whatsapp.view', 'whatsapp.bot_toggle'], ['visa']);

        $this->toggle(['enabled' => false], $narrow)->assertNotFound();
        $this->assertTrue($this->conv->fresh()->bot_enabled);
    }
}
