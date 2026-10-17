<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Jobs\ProcessWhatsAppReply;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\AdminTools;
use App\Support\AuditRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\PatchesWhatsAppSettings;
use Tests\TestCase;

/**
 * تدقيق الواتساب (E09): إعدادات البوت بلا أسرار، وفتح المحادثات بالعيّنة، وما يفعله البوت ورقم الإدارة منسوب إليهما.
 */
class WhatsAppAuditTest extends TestCase
{
    use ConfiguresWhatsApp;
    use PatchesWhatsAppSettings;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->fakeGraph();
    }

    private function rows(AuditAction $action)
    {
        return AuditLog::where('action', $action->value)->orderBy('id')->get();
    }

    public function test_settings_record_resulting_values_and_secrets_only_by_fingerprint(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $this->patchSettings($owner, [
            'rate_limit_per_hour' => 33,
            'system_prompt' => 'أنت مساعد الشركة. SECRET123 لا يظهر في السجل.',
        ])->assertOk();
        $this->actingAs($owner)->postJson('/api/whatsapp/settings/secrets/wa_token', ['value' => 'EAAG-SECRET123-token-value', 'reason' => 'routine'])->assertOk();

        $row = $this->rows(AuditAction::WaSettingsUpdate)->sole();
        $this->assertSame(['system_prompt', 'rate_limit_per_hour'], array_keys($row->changes['changed']));
        $this->assertSame(['from' => 20, 'to' => 33], $row->changes['changed']['rate_limit_per_hour']);
        $this->assertSame(AuditRedactor::fingerprint(null), $row->changes['changed']['system_prompt']['from']);

        $secret = $this->rows(AuditAction::WaSecretSet)->sole();
        $this->assertSame([['field' => 'wa_token', 'set' => true, 'fp' => substr(hash('sha256', 'EAAG-SECRET123-token-value'), 0, 8)]], $secret->changes['credentials']);
        $this->assertSame('routine', $secret->changes['reason']);
        $this->assertStringNotContainsString('SECRET123', json_encode(AuditLog::all()->toArray()));
        $this->assertCount(0, $this->rows(AuditAction::WaSuspendManual));
    }

    public function test_the_emergency_stop_and_the_bot_switch_each_get_their_own_row(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $this->killSwitch($owner, 'on')->assertOk();
        $this->patchSettings($owner, ['enabled' => false, 'pause_minutes' => 40])->assertOk();

        $stop = $this->rows(AuditAction::WaSuspendManual)->sole();
        $this->assertTrue($stop->changes['on']);
        $this->assertSame('سبب مكتوب للاختبار', $stop->changes['reason']);
        $this->assertSame('warn', $stop->severity);
        $this->assertFalse($this->rows(AuditAction::WaBotEnabled)->sole()->changes['on']);
    }

    public function test_opening_a_conversation_is_sampled_once_per_window_and_only_when_extended(): void
    {
        $admin = User::factory()->admin()->create();
        $conv = WaConversation::factory()->windowOpen()->create();

        $this->actingAs($admin)->getJson("/api/whatsapp/conversations/{$conv->id}/messages")->assertOk();
        $this->assertCount(0, $this->rows(AuditAction::WaConversationView));

        config(['audit.extended' => true]);
        $this->actingAs($admin)->getJson("/api/whatsapp/conversations/{$conv->id}/messages")->assertOk();
        $this->actingAs($admin)->getJson("/api/whatsapp/conversations/{$conv->id}/messages")->assertOk();

        $row = $this->rows(AuditAction::WaConversationView)->sole();
        $this->assertSame($conv->id, $row->entity_id);
        $this->assertNull($row->changes);
    }

    public function test_a_handoff_by_the_bot_is_attributed_to_the_bot_and_its_conversation(): void
    {
        config(['audit.extended' => true]);
        $conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'أريد موظفاً']);
        $this->fakeGemini(['call' => 'handoff_to_human', 'args' => ['reason' => 'طلب موظفاً']], 'سيتواصل معك أحد زملائنا.');

        (new ProcessWhatsAppReply($conv->id, 'tok'))->handle();

        $row = $this->rows(AuditAction::WaHandoff)->sole();
        $this->assertSame(['bot', 'conv:'.$conv->id], [$row->actor_type, $row->actor_ref]);
        $this->assertNull($row->user_id);
        $this->assertNull($row->ip_address, 'لا عنوان طلب لوظيفة طابور');
    }

    public function test_a_confirmed_admin_number_change_is_attributed_to_that_number_and_its_staff_user(): void
    {
        $staff = User::factory()->create();
        $conv = WaConversation::factory()->windowOpen()->create();
        $admin = WaAdminNumber::factory()->create(['phone' => $conv->phone, 'user_id' => $staff->id]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'أوقف بوت العملاء']);

        AdminTools::execute('set_customer_bot', ['action' => 'off'], $conv->fresh(), $admin->fresh());
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'تأكيد']);
        $out = AdminTools::execute('set_customer_bot', ['action' => 'off', 'confirm' => true], $conv->fresh(), $admin->fresh());

        $this->assertTrue($out['done']);
        $row = $this->rows(AuditAction::WaBotEnabled)->sole();
        $this->assertSame(['admin_number', (string) $admin->id, $staff->id], [$row->actor_type, $row->actor_ref, $row->user_id]);
        $this->assertSame($staff->name, $row->user_name);
        $this->assertFalse($row->changes['on']);
    }
}
