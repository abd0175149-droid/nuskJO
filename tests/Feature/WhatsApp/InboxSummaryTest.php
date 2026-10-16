<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\StuckReplies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * بطاقات أعلى الصندوق (E17.6): أعداد محصورة بنطاق الموظف، بلا بيانات شخصية، ومخزّنة 60 ثانية.
 */
class InboxSummaryTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.inbox_followup' => true]);
    }

    private function summary(User $u): array
    {
        return $this->actingAs($u)->getJson('/api/whatsapp/inbox-summary')->assertOk()->json();
    }

    private function failedOut(WaConversation $c, string $status = 'failed', $at = null): void
    {
        WaMessage::factory()->outbound()->create(['conversation_id' => $c->id, 'status' => $status, 'created_at' => $at ?? now()]);
    }

    public function test_counts_cover_the_staff_scope_only(): void
    {
        $specialist = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $mine = $this->conversationTagged(['umrah'], ['needs_attention' => true]);
        $this->conversationTagged(['visa'], ['needs_attention' => true]);
        $admin = $this->conversationTagged(['umrah'], ['needs_attention' => true]);
        $admin->forceFill(['is_admin_chat' => true])->save();
        $this->failedOut($mine);
        $this->failedOut($this->conversationTagged(['visa']));

        $s = $this->summary($specialist);
        $this->assertSame(1, $s['waiting_staff']);
        $this->assertSame(1, $s['failed_today']);
        $this->assertArrayNotHasKey('stuck', $s);
    }

    public function test_window_closing_matches_the_window_reminder_condition(): void
    {
        $manager = $this->staffWith('manager', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        WaConversation::factory()->create(['needs_attention' => true, 'last_inbound_at' => now()->subHours(22)]);
        $pending = WaConversation::factory()->create(['last_inbound_at' => now()->subHours(23)]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $pending->id]);
        // خُدم كل شيء: لا ينتظر أحداً
        WaConversation::factory()->create(['last_inbound_at' => now()->subHours(22)]);
        WaConversation::factory()->create(['needs_attention' => true, 'last_inbound_at' => now()->subHours(5)]);
        WaConversation::factory()->create(['needs_attention' => true, 'last_inbound_at' => now()->subHours(25)]);

        $this->assertSame(2, $this->summary($manager)['window_closing']);
        $ids = array_column($this->actingAs($manager)->getJson('/api/whatsapp/conversations?filter=window_closing')->json('conversations'), 'id');
        $this->assertCount(2, $ids);
    }

    public function test_failed_today_counts_undelivered_messages_of_today_only(): void
    {
        $manager = $this->staffWith('manager', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $c = WaConversation::factory()->windowOpen()->create();
        $this->failedOut($c, 'failed');
        $this->failedOut($c, 'unconfirmed');
        $this->failedOut($c, 'sent');
        $this->failedOut($c, 'failed', now()->subDays(2));

        $s = $this->summary($manager);
        $this->assertSame(2, $s['failed_today']);
        $this->assertSame(['failed' => 1, 'unconfirmed' => 1], $s['failed_breakdown']);
        $this->assertSame([$c->id], array_column($this->actingAs($manager)->getJson('/api/whatsapp/conversations?filter=failed_today')->json('conversations'), 'id'));
    }

    public function test_the_body_carries_numbers_only(): void
    {
        $manager = $this->staffWith('manager', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all', 'whatsapp.settings']);
        $c = WaConversation::factory()->windowOpen()->create(['display_name' => 'سامي العميل', 'needs_attention' => true]);
        Cache::put(StuckReplies::CACHE_KEY, [$c->id], 600);

        $raw = $this->actingAs($manager)->getJson('/api/whatsapp/inbox-summary')->assertOk()->getContent();
        $this->assertStringNotContainsString('سامي', $raw);
        $this->assertStringNotContainsString((string) $c->phone, $raw);
        $this->assertSame(1, json_decode($raw, true)['stuck']);
        $this->assertSame(['state'], array_keys(json_decode($raw, true)['channel']));
        $this->assertContains(json_decode($raw, true)['channel']['state'], ['ok', 'suspended', 'blocked', 'not_ready', 'bot_off']);
    }

    public function test_results_are_cached_for_sixty_seconds_per_user(): void
    {
        $manager = $this->staffWith('manager', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $this->assertSame(0, $this->summary($manager)['waiting_staff']);

        WaConversation::factory()->create(['needs_attention' => true]);
        $this->assertSame(0, $this->summary($manager)['waiting_staff']);

        $this->travel(61)->seconds();
        $this->assertSame(1, $this->summary($manager)['waiting_staff']);
    }

    public function test_the_inbox_page_carries_the_summary_when_the_flag_is_on(): void
    {
        $manager = $this->staffWith('manager', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $this->actingAs($manager)->get('/whatsapp/inbox')->assertInertia(fn ($p) => $p->where('followup', true)->has('summary.waiting_staff'));

        config(['features.env.inbox_followup' => false]);
        $this->actingAs($manager)->get('/whatsapp/inbox')->assertInertia(fn ($p) => $p->where('followup', false)->where('summary', null));
    }
}
