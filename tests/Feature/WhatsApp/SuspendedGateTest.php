<?php

namespace Tests\Feature\WhatsApp;

use App\Console\Commands\BackupSafeMode;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * قفل الإرسال الطارئ يوقف البوت والمساعد الإداري قبل أي نداء للنموذج،
 * ولا يُرفع أثناء مراجعة ما بعد الاستعادة.
 */
class SuspendedGateTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
        $this->fakeGraph();
        $this->fakeGemini('ملخّص اليوم جاهز.');
    }

    private function adminConversation(): WaConversation
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        WaAdminNumber::factory()->create(['phone' => $conv->phone]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'ملخص اليوم']);

        return $conv;
    }

    public function test_control_admin_number_is_served_when_not_suspended(): void
    {
        $this->assertTrue(BotEngine::reply($this->adminConversation())['sent']);
    }

    public function test_suspension_stops_the_admin_assistant_before_the_model(): void
    {
        WaBotSetting::current()->update(['wa_suspended' => true]);

        $result = BotEngine::reply($this->adminConversation());

        $this->assertFalse($result['sent']);
        $this->assertSame('الإرسال موقوف (قفل طارئ)', $result['reason']);
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('wa_admin_actions')->count());
    }

    public function test_suspension_from_config_also_stops_customer_replies(): void
    {
        config(['services.whatsapp.suspended' => true]);
        $conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'مرحبا']);

        $this->assertFalse(BotEngine::reply($conv)['sent']);
        Http::assertNothingSent();
    }

    public function test_lock_cannot_be_lifted_while_restore_review_is_pending(): void
    {
        $admin = User::factory()->admin()->create();
        WaBotSetting::current()->update(['wa_suspended' => true]);
        Setting::set(BackupSafeMode::REVIEW_KEY, json_encode(['snapshot' => '20261014T023000Z-scheduled']));

        $this->actingAs($admin)->put('/whatsapp/settings', ['wa_suspended' => false])
            ->assertSessionHasErrors('wa_suspended');
        $this->assertTrue(WaBotSetting::current()->fresh()->wa_suspended);

        // إبقاؤه مقفلاً وتعديل غيره مسموح
        $this->actingAs($admin)->put('/whatsapp/settings', ['wa_suspended' => true, 'pause_minutes' => 45])
            ->assertSessionHasNoErrors();
        $this->assertSame(45, (int) WaBotSetting::current()->fresh()->pause_minutes);
    }

    public function test_lock_can_be_lifted_without_a_pending_review(): void
    {
        $admin = User::factory()->admin()->create();
        WaBotSetting::current()->update(['wa_suspended' => true]);

        $this->actingAs($admin)->put('/whatsapp/settings', ['wa_suspended' => false])->assertSessionHasNoErrors();
        $this->assertFalse(WaBotSetting::current()->fresh()->wa_suspended);
    }
}
