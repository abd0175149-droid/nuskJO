<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaAdminAction;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Services\WhatsApp\AdminTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * get_channel_status (E23): للقراءة، بالصلاحية channel.view وحدها (إخفاء ثم إعادة فحص في التنفيذ)، بلا أسرار ولا نص
 * المدير ولا أرقام عملاء، وكل استدعاء في wa_admin_actions.
 */
class AdminChannelStatusToolTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel(['wa_token' => 'EAAG-tool-secret-token', 'wa_app_secret' => 'tool-app-secret-0001']);
        $this->conv = WaConversation::factory()->windowOpen()->create();
    }

    private function number(array $permissions): WaAdminNumber
    {
        $owner = User::factory()->admin()->create();

        return WaAdminNumber::factory()->create(['phone' => $this->conv->phone, 'permissions' => $permissions, 'user_id' => $owner->id]);
    }

    public function test_without_the_permission_the_tool_is_hidden_and_refused(): void
    {
        $a = $this->number(['report.daily']);

        $this->assertNotContains('get_channel_status', array_column(AdminTools::declarations($a), 'name'));
        $out = AdminTools::execute('get_channel_status', [], $this->conv, $a);

        $this->assertSame('غير مسموح', $out['error']);
        $this->assertSame('denied', WaAdminAction::where('tool', 'get_channel_status')->value('status'));
    }

    public function test_with_the_permission_it_reports_the_channel_without_secrets(): void
    {
        $a = $this->number(['channel.view']);
        DB::table('wa_bot_settings')->update([
            'wa_suspended' => true, 'wa_suspended_source' => 'manual', 'wa_suspended_reason' => 'نص المدير الخاص 0791234567',
            'wa_channel_health' => json_encode(['state' => 'ok', 'quality_rating' => 'GREEN', 'can_send_message' => 'AVAILABLE',
                'webhook' => ['ok' => false, 'issues' => ['callback_mismatch']], 'checked_at' => now()->toIso8601String()]),
            'wa_last_error' => json_encode(['meta_code' => 190, 'error_code' => 'TOKEN_INVALID', 'message' => 'رمز ميتا منتهٍ أو مُلغى', 'at' => now()->toIso8601String()]),
        ]);
        WaConversation::factory()->create(['phone' => '16505551234', 'last_inbound_at' => now()->subMinutes(5)]);

        $this->assertContains('get_channel_status', array_column(AdminTools::declarations($a), 'name'));
        $out = AdminTools::execute('get_channel_status', [], $this->conv, $a->fresh());

        $this->assertTrue($out['sending']['manual_suspended']);
        $this->assertSame('إيقاف طارئ يدوي', $out['sending']['suspended_by']);
        $this->assertSame('UNKNOWN', $out['sending']['health']);
        $this->assertSame('GREEN', $out['meta']['quality_rating']);
        $this->assertFalse($out['meta']['webhook_ok']);
        $this->assertSame('callback_mismatch', $out['meta']['webhook_issue']);
        $this->assertSame(['code' => 'TOKEN_INVALID', 'label' => 'رمز ميتا منتهٍ أو مُلغى'], array_intersect_key($out['last']['last_failure'], ['code' => 1, 'label' => 1]));
        $this->assertNotNull($out['last']['last_inbound_at']);
        $this->assertSame('v25.0', $out['graph']['version']);

        $json = json_encode($out, JSON_UNESCAPED_UNICODE);
        foreach (['EAAG-tool-secret-token', 'tool-app-secret-0001', 'test-llm-key', 'نص المدير الخاص', '16505551234', 'token"', 'secret', 'api_key'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        $this->assertSame('done', WaAdminAction::where('tool', 'get_channel_status')->value('status'));
    }

    public function test_the_permission_shows_up_in_the_admin_numbers_catalog(): void
    {
        $this->assertSame('التشخيص', AdminTools::CATALOG['channel.view']['group']);
        $this->assertFalse(AdminTools::CATALOG['channel.view']['write']);
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $props = $this->actingAs($owner)->get('/whatsapp/admin-numbers')->assertOk()->viewData('page')['props'];
        $this->assertStringContainsString('حالة قناة الواتساب', json_encode($props, JSON_UNESCAPED_UNICODE));
    }
}
