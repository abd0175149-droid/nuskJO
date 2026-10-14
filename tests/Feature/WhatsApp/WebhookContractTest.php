<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaOptout;
use App\Services\WhatsApp\ReplyState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * العقد الذهبي للويبهوك: حمولات ميتا الموقّعة تمر عبر المسار الحقيقي POST api/whatsapp/webhook
 * (التوقيع ← WhatsAppInbox ← جدولة ProcessWhatsAppReply)، والطابور مزيّف فلا يعمل البوت.
 */
class WebhookContractTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    private const CUSTOMER = '16505551234';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents($this->fixturePath($name)), true);
    }

    private function fixtureMessage(string $name): array
    {
        return data_get($this->fixture($name), 'entry.0.changes.0.value.messages.0');
    }

    private function fixtureStatus(string $name): array
    {
        return data_get($this->fixture($name), 'entry.0.changes.0.value.statuses.0');
    }

    /**
     * يضبط الساعة بعد وقت ميتا في التثبيتة بثوانٍ (وصول متأخر قليلاً)، ويعيد وقت ميتا نفسه.
     * أوقات التثبيتات ثابتة، والنافذة تُحسب من وقت ميتا لا من وقت الاستلام.
     */
    private function receivedShortlyAfter(string $fixture, int $lagSeconds = 7): Carbon
    {
        $metaAt = Carbon::createFromTimestamp((int) $this->fixtureMessage($fixture)['timestamp'], config('app.timezone'));
        $this->travelTo($metaAt->copy()->addSeconds($lagSeconds));

        return $metaAt;
    }

    /** محادثة العميل النموذجي، ونافذتها مفتوحة منذ ثلاث ساعات */
    private function existingConversation(): WaConversation
    {
        return WaConversation::factory()->create([
            'phone' => self::CUSTOMER,
            'wa_phone' => self::CUSTOMER,
            'last_inbound_at' => now()->subHours(3),
            'unread_count' => 0,
        ]);
    }

    private function outboundMessage(string $wamid): WaMessage
    {
        return WaMessage::factory()->outbound()->create([
            'conversation_id' => $this->existingConversation()->id,
            'wamid' => $wamid,
        ]);
    }

    // ==================== الرسالة النصية: الشكل الأساسي ====================

    public function test_text_message_is_stored_and_handed_to_the_bot(): void
    {
        $metaAt = $this->receivedShortlyAfter('text.json');
        $msg = $this->fixtureMessage('text.json');

        $this->postMetaWebhook('text.json')->assertOk()->assertExactJson(['ok' => true]);

        $message = WaMessage::sole();
        $this->assertSame($msg['id'], $message->wamid);
        $this->assertSame('in', $message->direction);
        $this->assertSame('customer', $message->source);
        $this->assertSame('text', $message->msg_type);
        $this->assertSame('Does it come in another color?', $message->body);
        $this->assertSame($msg, $message->payload);

        $conv = $message->conversation;
        $this->assertSame(self::CUSTOMER, $conv->phone);
        $this->assertSame(self::CUSTOMER, $conv->wa_phone);
        // اسم واتساب يضعه العميل بنفسه: للعرض فقط، ولا يُكتب فوق display_name (E11)
        $this->assertSame('Sheena Nelson', $conv->wa_profile_name);
        $this->assertSame('Sheena Nelson', $conv->label());
        // النافذة من وقت ميتا، لا من وقت الاستلام
        $this->assertTrue($conv->last_inbound_at->equalTo($metaAt));
        $this->assertTrue($message->meta_at->equalTo($metaAt));
        $this->assertSame(1, $conv->unread_count);
        $this->assertSame('open', $conv->status);

        // الوظيفة تحمل رمز الدمج الأحدث في الكاش
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);
        Queue::assertPushed(ProcessWhatsAppReply::class, fn ($job) => $job->conversationId === $conv->id
            && Cache::get(ProcessWhatsAppReply::debounceKey($conv->id)) === $job->token);
        Http::assertNothingSent();
    }

    // ==================== INV-02: منع التكرار ====================

    #[Group('INV-02')]
    public function test_same_payload_twice_stores_one_row_and_is_answered_once(): void
    {
        $this->receivedShortlyAfter('text.json');
        $this->fakeGemini('نعم، يتوفر بألوان أخرى.');

        $this->postMetaWebhook('text.json')->assertOk();
        $this->postMetaWebhook('text.json')->assertOk();

        $this->assertSame(1, WaMessage::count());
        $this->assertSame(1, WaConversation::sole()->unread_count);

        // المكرر وهي بلا رد يعيد التمرير (جدولة فشلت بعد التخزين لا تضيع)، والرمز الأحدث وحده يعمل
        $jobs = Queue::pushed(ProcessWhatsAppReply::class);
        $this->assertCount(2, $jobs);
        $jobs->each->handle();
        $this->assertSame(1, WaMessage::where('direction', 'out')->where('source', 'bot')->count());
    }

    #[Group('INV-02')]
    public function test_a_duplicate_after_the_reply_schedules_nothing(): void
    {
        $this->receivedShortlyAfter('text.json');
        $this->fakeGemini('نعم، يتوفر بألوان أخرى.');
        $this->postMetaWebhook('text.json')->assertOk();
        Queue::pushed(ProcessWhatsAppReply::class)->first()->handle();

        $this->postMetaWebhook('text.json')->assertOk();

        Queue::assertPushed(ProcessWhatsAppReply::class, 1);
        $this->assertSame(1, WaMessage::where('direction', 'out')->count());
    }

    #[Group('INV-02')]
    public function test_a_duplicate_opt_out_does_not_wake_the_bot(): void
    {
        $this->receivedShortlyAfter('optout_iqaf.json');

        $this->postMetaWebhook('optout_iqaf.json')->assertOk();
        $this->postMetaWebhook('optout_iqaf.json')->assertOk();

        Queue::assertNothingPushed();
        Http::assertSentCount(1);
        // إشعار النظام هو الرد: لا وارد معلّق يلتقطه المُصالِح
        $this->assertFalse(ReplyState::hasPending(WaConversation::sole()));
    }

    // ==================== INV-03: رقم آخر في التطبيق نفسه ====================

    #[Group('INV-03')]
    public function test_message_for_another_phone_number_id_is_ignored(): void
    {
        $this->postMetaWebhook('foreign_phone_number_id.json')->assertOk();

        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, WaConversation::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[Group('INV-03')]
    public function test_only_our_change_is_processed_when_batched_with_a_foreign_one(): void
    {
        $payload = $this->fixture('text.json');
        $payload['entry'][0]['changes'][] = data_get($this->fixture('foreign_phone_number_id.json'), 'entry.0.changes.0');

        $this->postRawWebhook(json_encode($payload))->assertOk();

        $this->assertSame([$this->fixtureMessage('text.json')['id']], WaMessage::pluck('wamid')->all());
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);
    }

    // ==================== INV-04: الويبهوك لا يفشل بعد التوقيع ====================

    public static function malformedBodies(): array
    {
        $text = file_get_contents(dirname(__DIR__, 2).'/Fixtures/whatsapp/text.json');

        return [
            'truncated json' => [substr($text, 0, 200)],
            'empty body' => [''],
            'not json' => ['not json at all'],
            'json scalar' => ['123'],
            'entry is a string' => ['{"object":"whatsapp_business_account","entry":"oops"}'],
            'changes is a string' => ['{"object":"whatsapp_business_account","entry":[{"changes":"oops"}]}'],
            'messages value is a string' => ['{"entry":[{"changes":[{"field":"messages","value":"oops"}]}]}'],
            'account event value is a string' => ['{"entry":[{"changes":[{"field":"account_update","value":"oops"}]}]}'],
            'message without id and from' => ['{"entry":[{"changes":[{"field":"messages","value":{"metadata":{"phone_number_id":"106540352242922"},"messages":[{"type":"text","text":{"body":"x"}}]}}]}]}'],
        ];
    }

    #[Group('INV-04')]
    #[DataProvider('malformedBodies')]
    public function test_malformed_payload_with_valid_signature_still_returns_200(string $raw): void
    {
        $this->postRawWebhook($raw)->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame(0, WaMessage::count());
        $this->assertFalse(WaBotSetting::current()->wa_suspended);
        Queue::assertNothingPushed();
    }

    // ==================== أنواع الرسائل ====================

    public function test_reaction_is_stored_but_not_scheduled_and_does_not_reopen_the_window(): void
    {
        $this->freezeSecond();
        $conv = $this->existingConversation();
        $windowBefore = $conv->last_inbound_at->copy();

        $this->postMetaWebhook('reaction.json')->assertOk();

        $message = WaMessage::sole();
        $this->assertSame('reaction', $message->msg_type);
        $this->assertSame('🙏', $message->body);
        $this->assertSame($this->fixtureMessage('reaction.json')['reaction'], $message->payload['reaction']);

        $conv->refresh();
        $this->assertTrue($conv->last_inbound_at->equalTo($windowBefore));
        $this->assertSame(0, $conv->unread_count);
        Queue::assertNothingPushed();
    }

    public static function interactiveReplies(): array
    {
        return [
            'button_reply' => ['interactive_button_reply.json', 'offer:12', 'تفاصيل العرض'],
            'list_reply' => ['interactive_list_reply.json', 'category:umrah', 'عروض العمرة'],
        ];
    }

    #[DataProvider('interactiveReplies')]
    public function test_interactive_reply_keeps_its_id_in_payload(string $fixture, string $id, string $title): void
    {
        $this->postMetaWebhook($fixture)->assertOk();

        $message = WaMessage::sole();
        $this->assertSame('interactive', $message->msg_type);
        $this->assertSame($title, $message->body);
        $this->assertSame($id, $message->payload['_button_id']);
        $this->assertSame($this->fixtureMessage($fixture)['context'], $message->payload['context']);
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);
    }

    public function test_voice_note_is_stored_with_its_media_id_and_scheduled(): void
    {
        $this->postMetaWebhook('audio.json')->assertOk();

        $message = WaMessage::sole();
        $this->assertSame('audio', $message->msg_type);
        $this->assertSame('🎤 رسالة صوتية', $message->body);
        $this->assertSame('1003383421387256', data_get($message->payload, 'audio.id'));
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);

        // التنزيل والتفريغ داخل الوظيفة، لا في الويبهوك
        Http::assertNothingSent();
    }

    public function test_unsupported_message_is_stored_with_its_error(): void
    {
        $this->postMetaWebhook('unsupported.json')->assertOk();

        $message = WaMessage::sole();
        $this->assertSame('unsupported', $message->msg_type);
        $this->assertSame('⚠️ رسالة غير مدعومة', $message->body);
        $this->assertSame(131051, data_get($message->payload, 'errors.0.code'));
    }

    // ==================== إيقاف التسويق ====================

    public function test_iqaf_opts_out_and_sends_the_system_notice_instead_of_the_bot(): void
    {
        $this->receivedShortlyAfter('optout_iqaf.json');
        $this->postMetaWebhook('optout_iqaf.json')->assertOk();

        $this->assertTrue(WaOptout::where('phone', self::CUSTOMER)->exists());
        Queue::assertNothingPushed();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://graph.facebook.com/v25.0/'.self::TEST_PHONE_NUMBER_ID.'/messages'
            && $r['to'] === self::CUSTOMER
            && $r['type'] === 'text'
            && str_starts_with($r['text']['body'], 'تم إيقاف الرسائل التسويقية'));

        $notice = WaMessage::where('direction', 'out')->sole();
        $this->assertSame('system', $notice->source);
        $this->assertSame('sent', $notice->status);
        $this->assertSame('wamid.OUT.1', $notice->wamid);
    }

    public function test_ilgha_alone_is_not_an_opt_out(): void
    {
        $this->postMetaWebhook('cancel_ilgha.json')->assertOk();

        $this->assertSame(0, WaOptout::count());
        $this->assertSame('إلغاء', WaMessage::sole()->body);
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);
        Http::assertNothingSent();
    }

    // ==================== حالات التسليم ====================

    public static function deliveryStatuses(): array
    {
        return [
            'sent' => ['status_sent.json', 'sent'],
            'delivered' => ['status_delivered.json', 'delivered'],
            'read' => ['status_read.json', 'read'],
            'played' => ['status_played.json', 'played'],
        ];
    }

    #[DataProvider('deliveryStatuses')]
    public function test_status_updates_the_outbound_message(string $fixture, string $status): void
    {
        $out = $this->outboundMessage($this->fixtureStatus($fixture)['id']);

        $this->postMetaWebhook($fixture)->assertOk();

        $out->refresh();
        $this->assertSame($status, $out->status);
        $this->assertSame($status, last($out->payload['statusHistory'])['status']);
        $this->assertNull($out->error_message);
        $this->assertSame(1, WaMessage::count());
        Queue::assertNothingPushed();
    }

    public function test_status_progression_is_recorded_in_order(): void
    {
        $out = $this->outboundMessage($this->fixtureStatus('status_sent.json')['id']);

        foreach (['status_sent.json', 'status_delivered.json', 'status_read.json'] as $fixture) {
            $this->postMetaWebhook($fixture)->assertOk();
        }

        $out->refresh();
        $this->assertSame('read', $out->status);
        $this->assertSame(['sent', 'delivered', 'read'], array_column($out->payload['statusHistory'], 'status'));
    }

    public function test_failed_131047_marks_the_message_failed_with_code_and_arabic_reason(): void
    {
        $out = $this->outboundMessage($this->fixtureStatus('status_failed_131047.json')['id']);

        $this->postMetaWebhook('status_failed_131047.json')->assertOk();

        $out->refresh();
        $this->assertSame('failed', $out->status);
        $this->assertSame('[131047] انتهت نافذة الـ24 ساعة عند ميتا — لا يمكن الإرسال حتى يراسلنا العميل.', $out->error_message);
        $this->assertSame(131047, $out->meta_code);
    }

    public function test_status_for_an_unknown_message_creates_nothing(): void
    {
        $this->postMetaWebhook('status_delivered.json')->assertOk();

        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, WaConversation::count());
    }

    // ==================== أحداث الحساب ====================

    public function test_account_ban_suspends_sending_and_notifies_admins(): void
    {
        $admin = User::factory()->admin()->create();

        $this->postMetaWebhook('account_update_ban.json')->assertOk();

        $this->assertTrue(WaBotSetting::current()->wa_suspended);
        $this->assertTrue(Notification::where('user_id', $admin->id)->where('title', '⛔ إيقاف إرسال الواتساب')->exists());
        Queue::assertNothingPushed();
    }

    public function test_benign_account_update_changes_nothing(): void
    {
        $this->postMetaWebhook('account_update_benign.json')->assertOk();
        $this->assertFalse(WaBotSetting::current()->wa_suspended);

        // ولا يرفع قفلاً قائماً
        $this->configureChannel(['wa_suspended' => true]);
        $this->postMetaWebhook('account_update_benign.json')->assertOk();
        $this->assertTrue(WaBotSetting::current()->wa_suspended);
    }
}
