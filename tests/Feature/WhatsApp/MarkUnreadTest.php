<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * «غير مقروء» الداخلي (E17): لا شيء يذهب لميتا، ولا يمحوه الاستطلاع؛ يمحوه الفتح الصريح أو رد الموظف.
 */
class MarkUnreadTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $staff;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        config(['features.env.inbox_followup' => true]);
        $this->staff = $this->staffWith('desk', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $this->conv = WaConversation::factory()->windowOpen()->create(['unread_count' => 0, 'last_message_at' => now()]);
    }

    private function mark(): void
    {
        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$this->conv->id}/mark-unread")
            ->assertOk()->assertJsonPath('marked_unread', true)->assertJsonPath('unread', 1);
    }

    public function test_marking_unread_is_internal_and_never_calls_meta(): void
    {
        Http::fake();
        $this->mark();

        Http::assertNothingSent();
        $fresh = $this->conv->fresh();
        $this->assertSame(1, (int) $fresh->unread_count);
        $this->assertSame($this->staff->id, (int) $fresh->marked_unread_by);

        $row = collect($this->actingAs($this->staff)->getJson('/api/whatsapp/conversations?filter=unread')->json('conversations'))->firstWhere('id', $this->conv->id);
        $this->assertTrue($row['marked_unread']);
        $this->assertSame($this->staff->name, $row['marked_unread_by']);
    }

    public function test_an_existing_count_is_kept(): void
    {
        $this->conv->update(['unread_count' => 4]);
        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$this->conv->id}/mark-unread")->assertJsonPath('unread', 4);
    }

    public function test_polling_and_reading_the_thread_do_not_clear_it_but_opening_does(): void
    {
        Http::fake();
        $this->mark();

        $this->actingAs($this->staff)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages")->assertOk();
        $this->actingAs($this->staff)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages?after=0")->assertOk();
        $this->actingAs($this->staff)->getJson('/api/whatsapp/conversations')->assertOk();
        $this->assertNotNull($this->conv->fresh()->marked_unread_at);

        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$this->conv->id}/read")->assertOk();
        $fresh = $this->conv->fresh();
        $this->assertSame(0, (int) $fresh->unread_count);
        $this->assertNull($fresh->marked_unread_at);
        $this->assertNull($fresh->marked_unread_by);
    }

    public function test_a_successful_staff_reply_clears_it(): void
    {
        $this->fakeGraph();
        $this->mark();

        WhatsAppChannel::sendText($this->conv->fresh(), 'أهلاً', 'staff', $this->staff->id);

        $this->assertNull($this->conv->fresh()->marked_unread_at);
        $this->assertSame(0, (int) $this->conv->fresh()->unread_count);
    }

    public function test_window_reminders_still_see_a_marked_conversation(): void
    {
        $this->conv->update(['needs_attention' => true, 'last_inbound_at' => now()->subHours(22), 'assigned_to' => $this->staff->id]);
        $this->mark();

        $this->artisan('whatsapp:window-reminders')->expectsOutputToContain('أُرسل 1');
    }
}
