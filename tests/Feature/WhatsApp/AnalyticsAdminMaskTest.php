<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/** محادثات الإدارة في التحليلات: «محادثة إدارة» بلا رابط ولا نص لمن لا يملك whatsapp.admin_chats، والمجاميع كما هي. */
class AnalyticsAdminMaskTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    private WaConversation $adminChat;

    private WaConversation $clientChat;

    protected function setUp(): void
    {
        parent::setUp();
        $number = WaAdminNumber::factory()->create();
        $this->adminChat = WaConversation::factory()->create(['phone' => $number->phone, 'wa_phone' => $number->phone]);
        $this->clientChat = WaConversation::factory()->create();

        foreach ([$this->adminChat, $this->clientChat] as $i => $conversation) {
            DB::table('wa_bot_usage')->insert([
                'conversation_id' => $conversation->id, 'source' => 'live', 'model' => 'gemini-test',
                'prompt_tokens' => 1000 * ($i + 1), 'output_tokens' => 100, 'created_at' => now(),
            ]);
            DB::table('wa_messages')->insert([
                ['conversation_id' => $conversation->id, 'direction' => 'in', 'source' => 'customer', 'msg_type' => 'text',
                    'body' => "سؤال سري {$i}", 'status' => 'received', 'created_at' => now(), 'updated_at' => now()],
                ['conversation_id' => $conversation->id, 'direction' => 'out', 'source' => 'system', 'msg_type' => 'text',
                    'body' => 'عطل', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    private function analytics(User $user): array
    {
        return $this->actingAs($user)->getJson('/api/whatsapp/analytics')->assertOk()->json();
    }

    public function test_settings_user_without_admin_chats_sees_a_masked_row(): void
    {
        $data = $this->analytics($this->staffWith('ops', ['whatsapp.settings']));

        $top = collect($data['usage']['top_conversations']);
        $this->assertNotContains($this->adminChat->id, $top->pluck('id'));
        $this->assertContains('محادثة إدارة', $top->pluck('name'));
        $this->assertContains($this->clientChat->id, $top->pluck('id'));

        $attention = collect($data['quality']['attention']);
        $this->assertNotContains($this->adminChat->id, $attention->pluck('conversation_id'));
        $this->assertNotContains('سؤال سري 0', $attention->pluck('question'));
        $this->assertContains('سؤال سري 1', $attention->pluck('question'));
    }

    public function test_masking_leaves_totals_unchanged_and_admin_chats_sees_everything(): void
    {
        $masked = $this->analytics($this->staffWith('ops', ['whatsapp.settings']));
        $full = $this->analytics($this->staffWith('owner', ['whatsapp.settings', 'whatsapp.admin_chats']));

        $this->assertSame($full['usage']['tiles'], $masked['usage']['tiles']);
        $this->assertSame($full['quality']['metrics'], $masked['quality']['metrics']);
        $this->assertContains($this->adminChat->id, collect($full['usage']['top_conversations'])->pluck('id'));
        $this->assertContains('سؤال سري 0', collect($full['quality']['attention'])->pluck('question'));
    }
}
