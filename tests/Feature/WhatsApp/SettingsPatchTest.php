<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\AdminTools;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\Settings\SettingsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\PatchesWhatsAppSettings;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * الحفظ الجزئي برمز النسخة (E10): ما لم يُرسل لا يتغيّر، وnull يعيد الافتراضي، والمفاتيح غير المسموحة تُرفض،
 * وأي كتابة أخرى بعد فتح الصفحة (مدير، مساعد إداري، إيقاف من ميتا) تجعل حفظها 409 فلا تلغيها.
 */
class SettingsPatchTest extends TestCase
{
    use ConfiguresWhatsApp;
    use PatchesWhatsAppSettings;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        WaBotSetting::current();
    }

    private function row(): array
    {
        return (array) DB::table('wa_bot_settings')->first();
    }

    public function test_defaults_come_from_the_schema_alone(): void
    {
        $s = WaBotSetting::current();

        foreach (SettingsSchema::defaults() as $key => $value) {
            $this->assertEquals($value, $s->{$key}, $key);
        }
        $this->assertSame(10, SettingsSchema::default('max_reply_age_minutes'));
        $this->assertSame(['min' => 2, 'max' => 120], array_intersect_key(SettingsSchema::FIELDS['max_reply_age_minutes'], ['min' => 0, 'max' => 0]));
        foreach ((new WaBotSetting)->getFillable() as $column) {
            $this->assertTrue(isset(SettingsSchema::FIELDS[$column]) || in_array($column, SettingsSchema::METADATA, true), "{$column} ليس في SettingsSchema");
        }
        $this->assertSame([], array_intersect(array_column(SettingsSchema::uiFields(), 'key'), SettingsSchema::secretKeys()));
        $this->assertSame([], array_intersect(SettingsSchema::patchableKeys(), ['knowledge_base', 'wa_suspended', 'wa_token', 'wa_app_secret', 'api_key', 'wa_verify_token']));
    }

    public function test_a_patch_changes_only_what_it_sends_and_null_restores_the_default(): void
    {
        $this->configureChannel(['system_prompt' => 'نص مخصص', 'pause_minutes' => 45]);
        $before = $this->row();

        $response = $this->patchSettings($this->owner, ['context_messages' => 30])->assertOk();

        $after = $this->row();
        $this->assertSame(30, (int) $after['context_messages']);
        $this->assertSame($before['settings_version'] + 1, (int) $after['settings_version']);
        $this->assertSame((int) $after['settings_version'], $response->json('version'));
        unset($before['context_messages'], $before['settings_version'], $before['updated_at'], $after['context_messages'], $after['settings_version'], $after['updated_at']);
        $this->assertSame($before, $after, 'بقية الأعمدة كما هي بايتاً بايتاً');

        $this->patchSettings($this->owner, ['system_prompt' => null, 'pause_minutes' => null])->assertOk();
        $this->assertNull($this->row()['system_prompt'], 'null = النص الافتراضي الحي');
        $this->assertSame(30, (int) $this->row()['pause_minutes']);
    }

    public function test_keys_outside_the_schema_and_the_dedicated_paths_are_refused(): void
    {
        foreach ([['not_a_field' => 1], ['wa_suspended' => false], ['wa_token' => 'x'], ['knowledge_base' => 'x'], ['tools_config' => ['not_a_tool' => true]]] as $changes) {
            $this->patchSettings($this->owner, $changes)->assertUnprocessable();
        }
        $this->patchSettings($this->owner, ['provider' => 'anthropic'])->assertJsonValidationErrors('confirm');
        $this->assertSame(1, (int) $this->row()['settings_version']);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_tool_switches_merge_key_by_key_and_null_restores_the_default(): void
    {
        $this->patchSettings($this->owner, ['tools_config' => ['save_note' => false, 'get_offers' => false]])->assertOk();
        $this->patchSettings($this->owner, ['tools_config' => ['get_offers' => null]])->assertOk();

        $this->assertSame(['save_note' => false], WaBotSetting::current()->fresh()->tools_config);
        $this->assertTrue(WaBotSetting::current()->fresh()->toolEnabled('get_offers'));
        $this->assertCount(count(BotTools::LABELS), BotTools::LABELS);
    }

    public function test_a_stale_version_is_refused_and_writes_nothing(): void
    {
        $this->patchSettings($this->owner, ['pause_minutes' => 50])->assertOk();

        $this->patchSettings($this->owner, ['context_messages' => 40], version: 1)
            ->assertStatus(409)->assertJson(['error' => 'stale', 'current_version' => 2]);
        $this->assertSame(20, (int) $this->row()['context_messages']);
    }

    public function test_an_admin_number_change_makes_an_open_page_stale(): void
    {
        $open = (int) WaBotSetting::current()->settings_version;
        $conv = WaConversation::factory()->windowOpen()->create();
        $admin = WaAdminNumber::factory()->create(['phone' => $conv->phone]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'أوقف أداة الملاحظات']);
        AdminTools::execute('manage_bot_tools', ['action' => 'disable', 'tool' => 'save_note'], $conv->fresh(), $admin->fresh());
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'تأكيد']);
        $this->assertTrue(AdminTools::execute('manage_bot_tools', ['action' => 'disable', 'tool' => 'save_note', 'confirm' => true], $conv->fresh(), $admin->fresh())['done']);

        $this->assertSame($open + 1, (int) $this->row()['settings_version']);
        $this->patchSettings($this->owner, ['pause_minutes' => 50], version: $open)->assertStatus(409);
        $this->assertFalse(WaBotSetting::current()->fresh()->toolEnabled('save_note'));
    }

    public function test_a_page_opened_before_an_automatic_stop_cannot_undo_it(): void
    {
        Queue::fake();
        $this->configureChannel();
        $open = (int) WaBotSetting::current()->settings_version;

        $this->postMetaWebhook('account_update_ban.json')->assertOk();

        $this->assertTrue((bool) WaBotSetting::current()->fresh()->wa_suspended);
        $this->assertSame('meta_event', WaBotSetting::current()->fresh()->wa_suspended_source);
        $this->patchSettings($this->owner, ['pause_minutes' => 50], version: $open)->assertStatus(409);
        $this->patchSettings($this->owner, ['wa_suspended' => false])->assertUnprocessable();
        $this->assertTrue((bool) WaBotSetting::current()->fresh()->wa_suspended);
    }

    public function test_one_audit_row_with_resulting_values_and_the_prompt_only_as_a_fingerprint(): void
    {
        $this->patchSettings($this->owner, ['system_prompt' => 'موجّه سري جداً', 'max_tool_loops' => 6])->assertOk();

        $row = AuditLog::where('action', AuditAction::WaSettingsUpdate->value)->sole();
        $this->assertSame(['from' => 4, 'to' => 6], $row->changes['changed']['max_tool_loops']);
        $this->assertSame(['len', 'sha1'], array_keys($row->changes['changed']['system_prompt']['to']));
        $this->assertStringNotContainsString('موجّه سري', json_encode($row->toArray()));
    }

    public function test_staff_without_the_permission_are_refused(): void
    {
        $sales = User::factory()->withPermissions(['whatsapp.view'])->create();

        $this->patchSettings($sales, ['pause_minutes' => 50])->assertForbidden();
        $this->actingAs($sales)->getJson('/api/whatsapp/settings')->assertForbidden();
        $this->assertSame(30, (int) $this->row()['pause_minutes']);
    }

    public function test_the_old_full_form_put_writes_nothing(): void
    {
        $this->actingAs($this->owner)->put('/whatsapp/settings', ['pause_minutes' => 99, 'wa_suspended' => false])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(30, (int) $this->row()['pause_minutes']);
        $this->assertSame(1, (int) $this->row()['settings_version']);
    }
}
