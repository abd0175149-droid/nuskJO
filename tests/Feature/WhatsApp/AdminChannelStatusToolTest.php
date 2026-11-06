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
 * get_channel_status (E23، أرقام OpenWA منذ 2026-11-06): للقراءة، بالصلاحية channel.view وحدها (إخفاء ثم إعادة فحص في التنفيذ)، بلا أسرار ولا نص
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
        $this->configureChannel();
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
        DB::table('wa_bot_settings')->update(['wa_suspended' => true, 'wa_suspended_source' => 'manual', 'wa_suspended_reason' => 'نص المدير الخاص 0791234567']);
        $this->testChannel()->update(['api_key' => 'gw-secret-key-0001', 'paused_until' => now()->addHour(), 'pause_reason' => 'manual']);
        $this->makeChannel(['name' => 'رقم الإشعارات', 'status' => 'disconnected']);
        WaConversation::factory()->create(['phone' => '16505551234', 'last_inbound_at' => now()->subMinutes(5)]);
        \App\Models\WaMessage::factory()->create(['conversation_id' => $this->conv->id, 'direction' => 'out', 'status' => 'failed', 'error_code' => 'CHANNEL_NOT_READY']);

        $this->assertContains('get_channel_status', array_column(AdminTools::declarations($a), 'name'));
        $out = AdminTools::execute('get_channel_status', [], $this->conv, $a->fresh());

        $this->assertTrue($out['sending']['manual_suspended']);
        $this->assertSame('إيقاف طارئ يدوي', $out['sending']['suspended_by']);
        $channels = collect($out['channels'])->keyBy('name');
        $this->assertNotNull($channels[$this->testChannel()->name]['paused_until']);
        $this->assertSame(['disconnected', false], [$channels['رقم الإشعارات']['status'], $channels['رقم الإشعارات']['ready']]);
        $this->assertSame('CHANNEL_NOT_READY', $out['last']['last_failure']['code']);
        $this->assertNotNull($out['last']['last_inbound_at']);

        $json = json_encode($out, JSON_UNESCAPED_UNICODE);
        foreach (['gw-secret-key-0001', self::TEST_WEBHOOK_SECRET, 'test-llm-key', 'نص المدير الخاص', '16505551234', 'api_key', 'sess-'] as $needle) {
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
