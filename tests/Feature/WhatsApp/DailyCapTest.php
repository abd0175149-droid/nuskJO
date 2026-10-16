<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\OutboundCounter;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * السقف الصلب للإرسال الذي يبدؤه النظام (E24): مصدر مبادر فوق wa_system_daily_cap يُرفض بلا نداء، وردود البوت
 * والموظف والنظام لا تُمس مهما بلغ العدّاد، و«السقف البارد صفر» بقفل النافذة لأي مصدر.
 */
class DailyCapTest extends TestCase
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

    private function cap(int $cap): void
    {
        WaBotSetting::current();
        DB::table('wa_bot_settings')->update(['wa_system_daily_cap' => $cap]);
    }

    public function test_an_initiated_source_over_the_cap_is_refused_without_calling_meta(): void
    {
        $this->cap(2);
        WaMessage::factory()->outbound()->count(2)->create(['conversation_id' => $this->conv->id, 'source' => 'proactive']);
        $this->fakeGraph();

        $r = WhatsAppChannel::sendText($this->conv, 'تذكير', 'proactive', sendKey: SendKey::reply($this->conv, 1, 'proactive'));

        $this->assertFalse($r['ok']);
        $this->assertSame(WhatsAppChannel::ERR_DAILY_CAP, $r['code']);
        Http::assertNothingSent();
        $this->assertSame(2, OutboundCounter::systemInitiatedToday());
    }

    public function test_under_the_cap_an_initiated_source_is_sent(): void
    {
        $this->cap(2);
        WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'source' => 'proactive']);
        // المرفوضة صراحة لا تُعدّ، والأمس خارج العدّ
        WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'source' => 'proactive', 'status' => 'failed']);
        WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'source' => 'template', 'created_at' => now()->subDays(2)]);
        $this->fakeGraph();

        $r = WhatsAppChannel::sendText($this->conv, 'تذكير', 'proactive', sendKey: SendKey::reply($this->conv, 1, 'proactive'));

        $this->assertTrue($r['ok']);
        Http::assertSentCount(1);
    }

    public function test_bot_staff_and_system_replies_ignore_the_cap(): void
    {
        $this->cap(0);
        WaMessage::factory()->outbound()->count(3)->create(['conversation_id' => $this->conv->id, 'source' => 'proactive']);
        $this->fakeGraph();
        $staff = User::factory()->admin()->create();

        $this->assertTrue(WhatsAppChannel::sendText($this->conv, 'رد البوت', 'bot', sendKey: SendKey::reply($this->conv, 1, 'text'))['ok']);
        $this->assertTrue(WhatsAppChannel::sendText($this->conv, 'رسالة الخلل', 'system', sendKey: SendKey::reply($this->conv, 1, 'failsafe'))['ok']);
        $this->assertTrue(WhatsAppChannel::sendText($this->conv, 'رد الموظف', 'staff', $staff->id)['ok']);
        Http::assertSentCount(3);
    }

    public function test_no_recent_inbound_is_refused_by_the_window_lock_for_any_source(): void
    {
        $this->cap(1000);
        $cold = WaConversation::factory()->create(['last_inbound_at' => null]);
        $this->fakeGraph();

        foreach (['proactive', 'template', 'bot', 'system'] as $source) {
            $r = WhatsAppChannel::sendText($cold, 'مرحبا', $source, sendKey: SendKey::reply($cold, 1, $source));
            $this->assertSame(WhatsAppChannel::ERR_WINDOW, $r['code'], $source);
        }
        Http::assertNothingSent();
    }
}
