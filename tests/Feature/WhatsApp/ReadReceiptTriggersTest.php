<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendWhatsAppReadReceipt;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\ReadReceipts;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * متى يصل ميتا إيصال القراءة (E35): بعد رد موظف ناجح (on_reply وon_open) بأعلى وارد لحظة الرد، وعند الفتح الصريح
 * للموكَّل (on_open وحده) مرة في الدقيقة. لا شيء من الاستطلاع، ولا من رد البوت، ولا من إرسال فاشل.
 */
class ReadReceiptTriggersTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    private WaMessage $in;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.read_receipts' => true]);
        $this->configureChannel(['send_read_receipts' => 'on_reply']);
        Bus::fake([SendWhatsAppReadReceipt::class]);
        $this->staff = $this->staffWith('replier', ['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all']);
        $this->conv = WaConversation::factory()->windowOpen()->create();
        $this->in = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.IN.1']);
    }

    private function mode(string $mode): void
    {
        WaBotSetting::current()->forceFill(['send_read_receipts' => $mode])->save();
    }

    private function sendAsStaff(): array
    {
        return WhatsAppChannel::sendText($this->conv, 'أهلاً، معك فريق نسك', 'staff', $this->staff->id, sendKey: SendKey::staff((string) Str::uuid()));
    }

    // ==================== رد الموظف ====================

    public function test_a_successful_staff_reply_schedules_one_receipt_up_to_the_newest_inbound(): void
    {
        $this->fakeGraph();

        $this->assertTrue($this->sendAsStaff()['ok']);

        Bus::assertDispatchedAfterResponse(SendWhatsAppReadReceipt::class,
            fn ($job) => $job->conversationId === $this->conv->id && $job->uptoMessageId === $this->in->id && $job->reason === 'reply');
        Bus::assertDispatchedAfterResponseTimes(SendWhatsAppReadReceipt::class, 1);
    }

    public function test_the_inbox_send_route_schedules_it_too(): void
    {
        $this->fakeGraph();

        $this->actingAs($this->staff)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/whatsapp/conversations/{$this->conv->id}/send", ['body' => 'تم، شكراً لك'])
            ->assertOk();

        Bus::assertDispatchedAfterResponse(SendWhatsAppReadReceipt::class, fn ($job) => $job->uptoMessageId === $this->in->id);
    }

    /** وارد يصل أثناء نداء الإرسال لا يُعلَّم مقروءاً: الدفتر يكتب صف الرد قبل النداء، والمسار القديم يحسب قبله */
    public function test_an_inbound_arriving_during_the_send_is_not_covered(): void
    {
        foreach ([false, true] as $ledger) {
            config(['features.env.send_ledger' => $ledger]);
            Http::fake(['graph.facebook.com/*' => function () {
                WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id]);

                return Http::response(['messages' => [['id' => 'wamid.OUT.'.Str::random(6)]]]);
            }]);
            $before = (int) WaMessage::where('direction', 'in')->max('id');

            $this->assertTrue($this->sendAsStaff()['ok']);

            Bus::assertDispatchedAfterResponse(SendWhatsAppReadReceipt::class, fn ($job) => $job->uptoMessageId === $before);
        }
    }

    public function test_a_failed_staff_send_schedules_nothing(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400)]);

        $this->assertFalse($this->sendAsStaff()['ok']);

        Bus::assertNotDispatchedAfterResponse(SendWhatsAppReadReceipt::class);
    }

    public function test_bot_replies_and_off_mode_schedule_nothing(): void
    {
        $this->fakeGraph();

        WhatsAppChannel::sendText($this->conv, 'رد آلي', 'bot', sendKey: SendKey::reply($this->conv, $this->in->id, 'text'));
        $this->mode('off');
        $this->sendAsStaff();
        config(['features.env.read_receipts' => false]);
        $this->mode('on_reply');
        $this->sendAsStaff();

        Bus::assertNotDispatchedAfterResponse(SendWhatsAppReadReceipt::class);
    }

    public function test_a_staff_reaction_is_not_a_reply(): void
    {
        $this->assertNull(ReadReceipts::replyUpto($this->conv, 'staff', 'reaction'));
        $this->assertSame($this->in->id, ReadReceipts::replyUpto($this->conv, 'staff', 'text'));
        $this->assertNull(ReadReceipts::replyUpto($this->conv, 'bot', 'text'));
        $this->assertNull(ReadReceipts::replyUpto($this->conv, 'system', 'text'));
    }

    public function test_the_job_sends_one_read_status_up_to_its_message(): void
    {
        $this->fakeGraph();
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.IN.LATER']);

        (new SendWhatsAppReadReceipt($this->conv->id, $this->in->id, 'reply'))->handle();

        Http::assertSent(fn (Request $r) => $r->data() === ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => 'wamid.IN.1']);
        Http::assertSentCount(1);
        $this->assertSame($this->in->id, $this->conv->fresh()->read_receipt_upto_id);
    }

    // ==================== الفتح الصريح ====================

    public function test_polling_the_thread_never_schedules_a_receipt(): void
    {
        $this->mode('on_open');

        $this->actingAs($this->staff)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages")->assertOk();
        $this->actingAs($this->staff)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages?".http_build_query(['after' => $this->in->id, 'since' => now()->subMinute()->format('Y-m-d H:i:s')]))->assertOk();

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_opening_with_on_reply_schedules_nothing(): void
    {
        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$this->conv->id}/read")->assertOk();

        Bus::assertNotDispatchedAfterResponse(SendWhatsAppReadReceipt::class);
    }

    public function test_opening_with_on_open_by_the_assignee_schedules_once_a_minute(): void
    {
        $this->mode('on_open');
        $this->conv->update(['assigned_to' => $this->staff->id, 'unread_count' => 2]);

        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$this->conv->id}/read")->assertOk();
        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$this->conv->id}/read")->assertOk();

        Bus::assertDispatchedAfterResponseTimes(SendWhatsAppReadReceipt::class, 1);
        Bus::assertDispatchedAfterResponse(SendWhatsAppReadReceipt::class, fn ($job) => $job->reason === 'open' && $job->uptoMessageId === $this->in->id);
        $this->assertSame(0, (int) $this->conv->fresh()->unread_count);
    }

    public function test_an_unassigned_conversation_counts_as_the_openers(): void
    {
        $this->mode('on_open');

        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$this->conv->id}/read")->assertOk();

        Bus::assertDispatchedAfterResponseTimes(SendWhatsAppReadReceipt::class, 1);
    }

    public function test_opening_by_someone_other_than_the_assignee_schedules_nothing(): void
    {
        $this->mode('on_open');
        $other = $this->staffWith('owner-of-it', ['whatsapp.view', 'whatsapp.reply']);
        $this->conv->update(['assigned_to' => $other->id]);

        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$this->conv->id}/read")->assertOk();

        Bus::assertNotDispatchedAfterResponse(SendWhatsAppReadReceipt::class);
    }

    public function test_opening_an_admin_number_chat_schedules_nothing(): void
    {
        $this->mode('on_open');
        $admin = $this->staffWith('admin-chats', ['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all', 'whatsapp.admin_chats']);
        WaAdminNumber::factory()->create(['phone' => $this->conv->phone]);

        $this->actingAs($admin)->postJson("/api/whatsapp/conversations/{$this->conv->id}/read")->assertOk();

        Bus::assertNotDispatchedAfterResponse(SendWhatsAppReadReceipt::class);
    }

    public function test_nothing_new_to_read_means_no_job(): void
    {
        $this->mode('on_open');
        $this->conv->update(['read_receipt_upto_id' => $this->in->id]);

        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$this->conv->id}/read")->assertOk();

        Bus::assertNotDispatchedAfterResponse(SendWhatsAppReadReceipt::class);
    }

    // ==================== الشفافية في الخيط ====================

    public function test_the_thread_shows_what_the_customer_sees(): void
    {
        $this->mode('on_open');
        $this->conv->update(['read_receipt_upto_id' => $this->in->id]);

        $c = $this->actingAs($this->staff)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages")->assertOk()->json('conversation');

        $this->assertSame($this->in->id, $c['read_receipt_upto_id']);
        $this->assertSame('on_open', $c['receipts_mode']);
        $this->assertTrue($c['receipts_open_notice']);

        // غير الموكَّل لا يرى التنبيه، والوضع الفعلي off حين يُطفأ العلم
        $this->conv->update(['assigned_to' => $this->staffWith('someone', ['whatsapp.view'])->id]);
        $this->assertFalse($this->actingAs($this->staff)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages")->json('conversation.receipts_open_notice'));
        config(['features.env.read_receipts' => false]);
        $this->assertSame('off', $this->actingAs($this->staff)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages")->json('conversation.receipts_mode'));
    }
}
