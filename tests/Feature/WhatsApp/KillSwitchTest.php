<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\PatchesWhatsAppSettings;
use Tests\TestCase;

/**
 * الإيقاف الطارئ (E10): مسار مستقل بسبب مكتوب، والاستئناف بكتابة «استئناف» ولمن يملك الإعدادات وحده،
 * والبيئة تتقدّم، وقفل من ميتا يحتاج تأكيد المراجعة قبل رفعه.
 */
class KillSwitchTest extends TestCase
{
    use ConfiguresWhatsApp;
    use PatchesWhatsAppSettings;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->fakeGraph();
        $this->owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
    }

    public function test_stopping_needs_a_real_reason_and_records_who_and_why(): void
    {
        $this->killSwitch($this->owner, 'on', ['reason' => 'قصير'])->assertJsonValidationErrors('reason');

        $this->killSwitch($this->owner, 'on')->assertOk()->assertJsonPath('killSwitch.source', 'manual');

        $s = WaBotSetting::current()->fresh();
        $this->assertTrue($s->wa_suspended);
        $this->assertSame([$this->owner->id, 'manual', 'سبب مكتوب للاختبار'], [$s->wa_suspended_by, $s->wa_suspended_source, $s->wa_suspended_reason]);
        $this->assertSame(WhatsAppChannel::ERR_SUSPENDED, WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'مرحبا')['code']);
        Http::assertNothingSent();
        $row = AuditLog::where('action', AuditAction::WaSuspendManual->value)->sole();
        $this->assertSame(['warn', $this->owner->id], [$row->severity, $row->user_id]);
    }

    public function test_the_kill_switch_role_can_stop_but_not_resume(): void
    {
        $oncall = User::factory()->withPermissions(['whatsapp.kill_switch'], 'oncall')->create();

        $this->killSwitch($oncall, 'on')->assertOk();
        $this->killSwitch($oncall, 'off')->assertForbidden();
        $this->assertTrue((bool) WaBotSetting::current()->fresh()->wa_suspended);
    }

    public function test_resuming_needs_the_typed_word(): void
    {
        $this->killSwitch($this->owner, 'on')->assertOk();

        $this->killSwitch($this->owner, 'off', ['confirm_text' => 'نعم'])->assertJsonValidationErrors('confirm_text');
        $this->killSwitch($this->owner, 'off')->assertOk()->assertJsonPath('killSwitch.effective', false);
        $this->assertSame(2, AuditLog::where('action', AuditAction::WaSuspendManual->value)->count());
    }

    public function test_a_stop_from_meta_needs_an_acknowledgement_to_resume(): void
    {
        WaBotSetting::current()->update(['wa_suspended' => true, 'wa_suspended_source' => 'meta_event', 'wa_suspended_reason' => 'ACCOUNT_RESTRICTION']);

        $this->killSwitch($this->owner, 'off')->assertJsonValidationErrors('acknowledge');
        $this->killSwitch($this->owner, 'off', ['acknowledge' => true])->assertOk();
        $this->assertSame('meta_event', AuditLog::where('action', AuditAction::WaSuspendManual->value)->sole()->changes['previous_source']);
    }

    public function test_an_environment_stop_cannot_be_lifted_here_and_shows_its_source(): void
    {
        config(['services.whatsapp.suspended' => true]);
        $staff = User::factory()->admin()->create();

        $this->killSwitch($this->owner, 'off')->assertStatus(409);
        $this->actingAs($this->owner)->getJson('/api/whatsapp/settings')
            ->assertJsonPath('killSwitch.effective', true)->assertJsonPath('killSwitch.source', 'env');
        $this->actingAs($staff)->get('/whatsapp/inbox')->assertInertia(fn ($page) => $page
            ->where('status.suspended', true)->where('status.suspended_source', 'env'));
    }
}
