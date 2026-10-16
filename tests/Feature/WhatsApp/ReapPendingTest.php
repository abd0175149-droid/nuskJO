<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * منظّف دفتر الإرسال (E19.8): المعلّق القديم «غير مؤكد» لا «فشل»، والمفتاح محجوز، والمحادثة «تدخّل» بإشعار واحد.
 * لا Http، ولا يمس المرسل ولا الحديث، و--dry-run لا يكتب، ومع العلم مطفأ لا شيء.
 */
class ReapPendingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.send_ledger' => true]);
        Http::fake();
    }

    private function row(WaConversation $conv, string $status, int $ageMinutes, string $key): WaMessage
    {
        $m = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => $status, 'wamid' => null, 'send_key' => $key]);
        WaMessage::whereKey($m->id)->update(['created_at' => now()->subMinutes($ageMinutes)]);

        return $m->refresh();
    }

    public function test_an_old_queued_row_becomes_unknown_and_flags_the_conversation_once(): void
    {
        $agent = User::factory()->withPermissions(['whatsapp.view'])->create();
        $conv = WaConversation::factory()->windowOpen()->create(['assigned_to' => $agent->id]);
        $old = $this->row($conv, 'queued', 20, 'reply:1:1:text');
        $fresh = $this->row($conv, 'queued', 5, 'reply:1:2:text');
        $sent = $this->row($conv, 'sent', 30, 'reply:1:3:text');

        Artisan::call('whatsapp:reap-pending');
        Artisan::call('whatsapp:reap-pending');

        $old->refresh();
        $this->assertSame(['unknown', 'reply:1:1:text', 'OUTCOME_UNKNOWN'], [$old->status, $old->send_key, $old->error_code]);
        $this->assertArrayHasKey('_reaped', $old->payload);
        $this->assertSame('queued', $fresh->refresh()->status);
        $this->assertSame('sent', $sent->refresh()->status);
        $this->assertSame([true, 'send_unknown'], [$conv->refresh()->needs_attention, $conv->attention_reason]);
        $this->assertSame(1, Notification::where('user_id', $agent->id)->count());
        Http::assertNothingSent();
    }

    public function test_an_old_unknown_row_is_only_marked(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $row = $this->row($conv, 'unknown', 20, 'reply:1:1:text');

        Artisan::call('whatsapp:reap-pending');

        $row->refresh();
        $this->assertSame(['unknown', 'reply:1:1:text'], [$row->status, $row->send_key]);
        $this->assertArrayHasKey('_reaped', $row->payload);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $row = $this->row($conv, 'queued', 20, 'reply:1:1:text');

        Artisan::call('whatsapp:reap-pending', ['--dry-run' => true]);

        $this->assertStringContainsString('[تجربة] غير مؤكدة الآن: 1', Artisan::output());
        $this->assertSame('queued', $row->refresh()->status);
        $this->assertFalse((bool) $conv->refresh()->needs_attention);
    }

    public function test_nothing_happens_without_the_flag(): void
    {
        config(['features.env.send_ledger' => false]);
        $conv = WaConversation::factory()->windowOpen()->create();
        $row = $this->row($conv, 'queued', 20, 'reply:1:1:text');

        Artisan::call('whatsapp:reap-pending');

        $this->assertSame('queued', $row->refresh()->status);
    }

    public function test_a_burst_alerts_the_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $conv = WaConversation::factory()->windowOpen()->create();
        foreach (range(1, 6) as $i) {
            $this->row($conv, 'queued', 20, "reply:1:{$i}:text");
        }

        Artisan::call('whatsapp:reap-pending');

        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', '⚠️ رسائل واتساب لم يُتأكد وصولها')->count());
    }
}
