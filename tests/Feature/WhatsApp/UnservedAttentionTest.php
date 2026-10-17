<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * كل وارد لا يرد عليه البوت يصير «تدخّل» بسبب ظاهر، ويُشعَر الموكَّل وإلا المدراء
 * (مرة كل 30 دقيقة للمحادثة، ولا إشعار إن ردّ موظف خلال 10 دقائق). النص عام بلا اسم ولا نص.
 */
class UnservedAttentionTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        Http::fake();
        $this->admin = User::factory()->admin()->create();
    }

    private function conversationWaiting(array $attributes = []): WaConversation
    {
        $conv = WaConversation::factory()->windowOpen()->create($attributes + ['display_name' => 'عميل سري']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'نص العميل الخاص']);

        return $conv;
    }

    public static function reasons(): array
    {
        return [
            'bot off system-wide' => [['enabled' => false], [], 'bot_off'],
            'bot switched off for the conversation' => [[], ['bot_enabled' => false], 'with_staff'],
            'bot paused after a staff reply' => [[], ['bot_paused_until' => now()->addMinutes(20)], 'bot_paused'],
            'window closed' => [[], ['last_inbound_at' => now()->subDays(2)], 'window_closed'],
        ];
    }

    #[DataProvider('reasons')]
    public function test_an_unserved_inbound_becomes_attention_with_a_reason(array $settings, array $conversation, string $reason): void
    {
        if ($settings) {
            $this->configureChannel($settings);
        }
        $conv = $this->conversationWaiting($conversation);

        $this->assertSame($reason, BotEngine::reply($conv)['reason']);

        $conv->refresh();
        $this->assertTrue($conv->needs_attention);
        $this->assertSame($reason, $conv->attention_reason);
        $this->assertSame((int) WaMessage::max('id'), $conv->bot_answered_in_id, 'المحادثة صارت للموظف');
        Http::assertNothingSent();
    }

    public function test_admins_are_notified_once_with_a_generic_text(): void
    {
        $this->configureChannel(['enabled' => false]);
        $conv = $this->conversationWaiting();

        BotEngine::reply($conv);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id]);
        BotEngine::reply($conv->fresh());

        $alerts = Notification::where('user_id', $this->admin->id)->get();
        $this->assertCount(1, $alerts, 'مرة كل 30 دقيقة للمحادثة');
        $this->assertSame("محادثة #{$conv->id} — 🔕 البوت مطفأ", $alerts[0]->body);
        $this->assertStringNotContainsString('عميل سري', $alerts->toJson(JSON_UNESCAPED_UNICODE));
    }

    public function test_the_assignee_is_notified_instead_of_admins(): void
    {
        $agent = User::factory()->withPermissions(['whatsapp.view'], 'agent')->create();
        $conv = $this->conversationWaiting(['bot_enabled' => false, 'assigned_to' => $agent->id]);

        BotEngine::reply($conv);

        $this->assertSame(1, Notification::where('user_id', $agent->id)->count());
        $this->assertSame(0, Notification::where('user_id', $this->admin->id)->count());
    }

    public function test_no_notification_while_staff_is_replying_in_the_conversation(): void
    {
        $staff = User::factory()->withPermissions(['whatsapp.view'], 'desk')->create();
        $conv = $this->conversationWaiting(['bot_paused_until' => now()->addMinutes(20)]);
        WaMessage::factory()->fromStaff($staff)->create(['conversation_id' => $conv->id]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id]);

        BotEngine::reply($conv);

        $this->assertTrue($conv->fresh()->needs_attention);
        $this->assertSame(0, Notification::count());
    }

    public function test_a_finally_failed_job_flags_the_conversation(): void
    {
        $conv = $this->conversationWaiting();

        (new ProcessWhatsAppReply($conv->id, 't'))->failed(new RuntimeException('boom'));

        $this->assertSame('job_failed', $conv->fresh()->attention_reason);
    }

    public function test_handoff_sets_the_reason_and_toggling_or_resolving_clears_it(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all', 'whatsapp.reply', 'whatsapp.bot_toggle'], 'owner')->create();
        $conv = $this->conversationWaiting(['bot_enabled' => false]);
        BotEngine::reply($conv);
        $this->assertSame('with_staff', $conv->fresh()->attention_reason);

        $this->actingAs($owner)->getJson('/api/whatsapp/conversations')->assertJsonPath('conversations.0.attention_reason', 'with_staff');
        $this->actingAs($owner)->postJson("/api/whatsapp/conversations/{$conv->id}/resolve")->assertOk();
        $this->assertNull($conv->fresh()->attention_reason);

        $conv->update(['attention_reason' => 'handoff', 'needs_attention' => true]);
        $this->actingAs($owner)->postJson("/api/whatsapp/conversations/{$conv->id}/toggle-bot")->assertOk();
        $this->assertNull($conv->fresh()->attention_reason);
    }
}
