<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaCall;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * ما يصل الصندوق من المكالمات والتعايش (E39): حالة المكالمة في الخيط، ووسم هاتف الوكالة بالحامل وعلامتا التعديل والحذف،
 * وفلتر «مكالمات فائتة» بعد السياج، والأعلام والشارة للواجهة.
 */
class CallsInboxPayloadTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        config(['features.env.calls_webhook' => true, 'features.env.coexistence_echoes' => true, 'features.env.inbox_v2' => true]);
        $this->admin = User::factory()->admin()->create(['name' => 'المدير']);
    }

    public function test_the_thread_carries_the_call_state_and_the_phone_holder_and_marks(): void
    {
        $conv = WhatsAppInbox::conversationFor('16505551234');
        $call = WaMessage::create(['conversation_id' => $conv->id, 'direction' => 'in', 'source' => 'system', 'msg_type' => 'call', 'body' => '📞 مكالمة واردة فائتة 10:42']);
        WaCall::create(['conversation_id' => $conv->id, 'wa_call_id' => 'wacid.TEST.1', 'direction' => 'USER_INITIATED', 'status' => 'missed', 'missed' => true, 'thread_message_id' => $call->id]);
        WaMessage::create(['conversation_id' => $conv->id, 'direction' => 'out', 'source' => 'staff_phone', 'msg_type' => 'text', 'body' => 'من الهاتف',
            'status' => 'sent', 'staff_id' => $this->admin->id, 'wamid' => 'wamid.TEST.echo', 'payload' => ['_echo' => true, '_edited_at' => now()->toIso8601String()]]);
        WaMessage::create(['conversation_id' => $conv->id, 'direction' => 'out', 'source' => 'staff_phone', 'msg_type' => 'text', 'body' => 'بلا حامل',
            'status' => 'sent', 'wamid' => 'wamid.TEST.echo2', 'payload' => ['_echo' => true]]);

        $res = $this->actingAs($this->admin)->getJson("/api/whatsapp/conversations/{$conv->id}/messages")->assertOk();

        [$callRow, $echo, $anonymous] = $res->json('messages');
        $this->assertSame(['call', true, ['missed' => true]], [$callRow['type'], $callRow['is_system'], $callRow['call']]);
        $this->assertSame('📱 من هاتف الوكالة · المدير', $echo['sender']);
        $this->assertSame(['edited' => true], $echo['phone']);
        $this->assertSame('📱 من هاتف الوكالة', $anonymous['sender']);
        $this->assertArrayNotHasKey('phone', $anonymous);
    }

    public function test_the_missed_calls_filter_stays_inside_the_fence(): void
    {
        $agent = User::factory()->withPermissions(['whatsapp.view'], 'agent')->create();
        $mine = WhatsAppInbox::conversationFor('16505551234');
        $mine->forceFill(['assigned_to' => $agent->id, 'needs_attention' => true, 'attention_reason' => 'missed_call'])->save();
        $other = WaConversation::create(['phone' => '16505553333', 'bot_enabled' => true, 'status' => 'open', 'needs_attention' => true, 'attention_reason' => 'missed_call']);
        WaConversation::create(['phone' => '15550783881', 'bot_enabled' => true, 'status' => 'open', 'needs_attention' => true, 'attention_reason' => 'handoff']);

        $ids = fn (User $u) => collect($this->actingAs($u)->getJson('/api/whatsapp/conversations?filter=missed_call')->assertOk()->json('conversations'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$mine->id], $ids($agent));
        $this->assertSame([$mine->id, $other->id], $ids($this->admin));
    }

    public function test_the_inbox_page_gets_the_calls_flag_and_the_coexistence_badge(): void
    {
        $this->actingAs($this->admin)->get('/whatsapp/inbox')->assertOk()
            ->assertInertia(fn ($page) => $page->where('calls', true)->where('status.coexistence', true));

        config(['features.env.calls_webhook' => false, 'features.env.coexistence_echoes' => false]);
        $this->actingAs($this->admin)->get('/whatsapp/inbox')->assertOk()
            ->assertInertia(fn ($page) => $page->where('calls', false)->where('status.coexistence', false));
    }
}
