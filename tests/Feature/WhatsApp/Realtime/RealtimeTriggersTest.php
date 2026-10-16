<?php

namespace Tests\Feature\WhatsApp\Realtime;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\TopicRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\CapturesBroadcasts;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * نقاط إطلاق البث اللحظي (E28): الوارد والصادر والحالة من أحداث E21، وأفعال الموظف من حفظ المحادثة، والتوسيم
 * وإزالته، وإيقاظ المؤجّل. كل حدث يصل من يرى المحادثة لحظته، ومن فقدها بالفعل نفسه، ولا يصل غيرهم.
 */
class RealtimeTriggersTest extends TestCase
{
    use BuildsWhatsAppStaff, CapturesBroadcasts, ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    private const CUSTOMER = '16505551234';

    private User $admin;

    private User $umrah;

    private User $visa;

    private User $disabled;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableInboxRealtime();
        $this->captureBroadcasts();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();

        $this->admin = User::factory()->admin()->create();
        $this->umrah = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $this->visa = $this->staffWith('visa-desk', self::INBOX_PERMISSIONS, ['visa']);
        $this->disabled = $this->staffWith('umrah-desk-2', self::INBOX_PERMISSIONS, ['umrah']);
        $this->disabled->forceFill(['is_active' => false])->save();
    }

    private function customerTagged(array $topics, array $attributes = []): WaConversation
    {
        return $this->conversationTagged($topics, ['phone' => self::CUSTOMER, 'wa_phone' => self::CUSTOMER] + $attributes);
    }

    /** وقت الوصول بعد وقت ميتا في التثبيتة بثوانٍ (النافذة من وقت ميتا) */
    private function arriveAfter(string $fixture): void
    {
        $raw = json_decode(file_get_contents($this->fixturePath($fixture)), true);
        $ts = data_get($raw, 'entry.0.changes.0.value.messages.0.timestamp') ?? data_get($raw, 'entry.0.changes.0.value.statuses.0.timestamp');
        $this->travelTo(Carbon::createFromTimestamp((int) $ts, config('app.timezone'))->addSeconds(7));
    }

    public function test_an_inbound_message_reaches_the_admin_and_the_topic_specialist_only(): void
    {
        $conversation = $this->customerTagged(['umrah']);
        $this->arriveAfter('text.json');

        $this->postMetaWebhook('text.json')->assertOk();

        $this->assertCount(1, $this->inboxBroadcasts());
        $this->assertSame(['c' => $conversation->id, 'k' => 'message'], $this->inboxBroadcasts()[0]['payload']);
        $this->assertSame([$this->admin->id, $this->umrah->id], $this->recipientsOf($conversation->id));
    }

    public function test_a_delivery_status_is_a_status_event(): void
    {
        $conversation = $this->customerTagged(['visa'], ['last_inbound_at' => now()]);
        WaMessage::factory()->outbound()->create(['conversation_id' => $conversation->id, 'status' => 'sent',
            'wamid' => 'wamid.HBgLMTY1MDU1NTEyMzQVAgARGBI0MkZERjYwREJFODBFQTIxNDEA']);
        $this->arriveAfter('status_delivered.json');

        $this->postMetaWebhook('status_delivered.json')->assertOk();

        $this->assertSame(['c' => $conversation->id, 'k' => 'status'], $this->inboxBroadcasts()[0]['payload'] ?? null);
        $this->assertSame([$this->admin->id, $this->visa->id], $this->recipientsOf($conversation->id, 'status'));
    }

    public function test_a_staff_reply_is_one_message_event_for_the_request(): void
    {
        $conversation = $this->customerTagged(['umrah'], ['last_inbound_at' => now()->subHour()]);

        $this->actingAs($this->umrah)->postJson("/api/whatsapp/conversations/{$conversation->id}/send", ['body' => 'أهلاً بك'])->assertOk();

        $this->assertCount(1, $this->inboxBroadcasts());
        $this->assertSame('message', $this->inboxBroadcasts()[0]['payload']['k']);
        $this->assertSame([$this->admin->id, $this->umrah->id], $this->recipientsOf($conversation->id));
    }

    public function test_reassigning_tells_the_old_and_the_new_assignee(): void
    {
        $a = $this->staffWith('desk-a', self::INBOX_PERMISSIONS);
        $b = $this->staffWith('desk-b', self::INBOX_PERMISSIONS);
        $conversation = $this->customerTagged([], ['assigned_to' => $a->id]);

        $this->actingAs($this->admin)->postJson("/api/whatsapp/conversations/{$conversation->id}/assign", ['user_id' => $b->id])->assertOk();

        $this->assertSame([$this->admin->id, $a->id, $b->id], $this->recipientsOf($conversation->id, 'meta'));
        // A لم يعد يراها: الحدث رقم فقط، وأي جلب بعده 404
        $this->actingAs($a)->getJson("/api/whatsapp/conversations/{$conversation->id}/messages")->assertNotFound();
    }

    public function test_removing_a_tag_tells_the_specialist_who_lost_the_conversation(): void
    {
        $conversation = $this->customerTagged(['visa', 'umrah'], ['assigned_to' => $this->admin->id]);

        $this->actingAs($this->admin)->postJson("/api/whatsapp/conversations/{$conversation->id}/topic", ['topic' => 'visa', 'attach' => false])->assertOk();

        $this->assertSame([$this->admin->id, $this->umrah->id, $this->visa->id], $this->recipientsOf($conversation->id, 'meta'));
    }

    public function test_tagging_by_the_bot_reaches_the_new_specialist_at_once(): void
    {
        $conversation = $this->customerTagged([]);
        DB::table('wa_topic_users')->where('user_id', $this->umrah->id)->update(['is_primary' => true]);

        // سياق الطابور: لا طلب، فيُبث بعد الاعتماد مباشرة
        TopicRouter::tag($conversation, 'umrah');

        $this->assertSame($this->umrah->id, $conversation->fresh()->assigned_to);
        $this->assertSame([$this->admin->id, $this->umrah->id], $this->recipientsOf($conversation->id, 'meta'));
        $this->assertNotContains($this->disabled->id, $this->recipientsOf($conversation->id));
    }

    public function test_staff_actions_on_the_conversation_are_meta_events(): void
    {
        $conversation = $this->customerTagged(['umrah'], ['needs_attention' => true, 'unread_count' => 3, 'bot_enabled' => true]);

        foreach ([['resolve', []], ['toggle-bot', ['enabled' => false]], ['read', []]] as [$action, $payload]) {
            $this->broadcasts = [];
            $this->actingAs($this->umrah)->postJson("/api/whatsapp/conversations/{$conversation->id}/{$action}", $payload)->assertOk();
            $this->assertSame([$this->admin->id, $this->umrah->id], $this->recipientsOf($conversation->id, 'meta'), $action);
        }
    }

    public function test_an_automatic_handoff_outside_a_request_is_broadcast(): void
    {
        $conversation = $this->customerTagged(['umrah']);

        // وضع العطل (E12) ومنظّف الردود يرفعان «تدخّل» بحفظ المحادثة في الطابور
        $conversation->update(['needs_attention' => true, 'attention_reason' => 'llm_outage']);

        $this->assertSame([$this->admin->id, $this->umrah->id], $this->recipientsOf($conversation->id, 'meta'));
    }

    public function test_a_due_snooze_is_broadcast(): void
    {
        $conversation = $this->customerTagged(['umrah'], ['snoozed_until' => now()->subMinute()]);

        Artisan::call('whatsapp:snooze-wake');

        $this->assertTrue($conversation->fresh()->needs_attention);
        $this->assertSame([$this->admin->id, $this->umrah->id], $this->recipientsOf($conversation->id, 'meta'));
    }

    public function test_the_inbox_page_tells_the_client_whether_realtime_is_on(): void
    {
        config(['features.env.inbox_v2' => true]);
        $this->actingAs($this->umrah)->get('/whatsapp/inbox')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('realtime.inbox', true));

        config(['features.env.inbox_realtime' => false]);
        $this->actingAs($this->umrah)->get('/whatsapp/inbox')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('realtime.inbox', false));
    }

    public function test_fields_the_inbox_does_not_show_are_not_broadcast(): void
    {
        $conversation = $this->customerTagged(['umrah']);

        $conversation->update(['bot_answered_in_id' => 99, 'last_outbound_at' => now()]);

        $this->assertSame([], $this->inboxBroadcasts());
    }
}
