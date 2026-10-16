<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaCall;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaWebhookEvent;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\CallEventHandler;
use App\Services\WhatsApp\ReplyState;
use App\Services\WhatsApp\WebhookFields;
use App\Services\WhatsApp\WebhookIntake;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * حقل calls (E39، العلم calls_webhook): السجل والنافذة ورسالة الخيط والفائتة إلى «تدخّل» وإشعار المختص مرة واحدة،
 * بلا أي رد ولا SDP محفوظ ولا رقم في السجل. وبدون العلم سلوك E20 كما هو (WebhookFenceTest).
 */
class CallWebhookTest extends TestCase
{
    use CapturesLogs, ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        config(['features.env.calls_webhook' => true]);
        $this->assertTrue(CallEventHandler::enabled());
    }

    /** الحمولة بوقت قريب (الوقت لا يتجاوز «الآن»، والنافذة تُحسب منه) */
    private function calls(string $fixture, array $override = [], int $secondsAgo = 30): string
    {
        $payload = json_decode((string) file_get_contents($this->fixturePath($fixture)), true);
        $call = $payload['entry'][0]['changes'][0]['value']['calls'][0];
        $call['timestamp'] = (string) (now()->getTimestamp() - $secondsAgo);
        $payload['entry'][0]['changes'][0]['value']['calls'][0] = array_merge($call, $override);

        return (string) json_encode($payload);
    }

    private function conversation(array $attributes = []): WaConversation
    {
        $conv = WhatsAppInbox::conversationFor('16505551234');
        $conv->forceFill($attributes)->save();

        return $conv->fresh();
    }

    public function test_a_customer_call_logs_the_row_and_reopens_a_closed_window_without_any_reply(): void
    {
        $conv = $this->conversation(['last_inbound_at' => now()->subDays(2)]);
        $this->assertFalse($conv->isWindowOpen());

        $this->postRawWebhook($this->calls('calls_connect_user.json'))->assertOk();

        $call = WaCall::sole();
        $this->assertSame(['ringing', 'USER_INITIATED', $conv->id, false], [$call->status, $call->direction, (int) $call->conversation_id, $call->missed]);
        $conv->refresh();
        $this->assertTrue($conv->isWindowOpen(), 'اتصال العميل يفتح النافذة');
        $this->assertSame($call->connect_at->getTimestamp(), $conv->last_inbound_at->getTimestamp());
        $this->assertSame(0, WaMessage::count(), 'الاتصال وحده لا رسالة في الخيط');
        Http::assertNothingSent();
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
    }

    public function test_an_older_connect_never_moves_the_window_back(): void
    {
        $conv = $this->conversation(['last_inbound_at' => now()->subMinutes(5)]);
        $before = $conv->last_inbound_at->getTimestamp();

        $this->postRawWebhook($this->calls('calls_connect_user.json', secondsAgo: 3600))->assertOk();

        $this->assertSame($before, $conv->fresh()->last_inbound_at->getTimestamp());
        $this->assertSame(1, WaCall::count());
    }

    public function test_a_failed_call_without_duration_is_missed_raises_attention_and_notifies_the_assignee_once(): void
    {
        $agent = User::factory()->withPermissions(['whatsapp.view'], 'agent')->create();
        $admin = User::factory()->admin()->create();
        $conv = $this->conversation(['assigned_to' => $agent->id, 'display_name' => 'عميل تجريبي']);

        $this->postRawWebhook($this->calls('calls_connect_user.json'))->assertOk();
        $this->postRawWebhook($this->calls('calls_terminate_failed.json', secondsAgo: 5))->assertOk();

        $call = WaCall::sole();
        $this->assertTrue($call->missed);
        $this->assertSame('missed', $call->status);
        $this->assertNull($call->duration_seconds);
        $message = WaMessage::sole();
        $this->assertSame(['in', 'system', 'call', null], [$message->direction, $message->source, $message->msg_type, $message->wamid]);
        $this->assertStringStartsWith('📞 مكالمة واردة فائتة ', $message->body);
        $this->assertSame($message->id, (int) $call->thread_message_id);
        $conv->refresh();
        $this->assertTrue($conv->needs_attention);
        $this->assertSame('missed_call', $conv->attention_reason);
        $this->assertStringStartsWith('📞 مكالمة واردة فائتة', $conv->last_message_preview);
        $this->assertSame(1, Notification::where('user_id', $agent->id)->where('title', '📞 مكالمة فائتة')->count());
        $this->assertSame(0, Notification::where('user_id', $admin->id)->count(), 'المختص أولاً، لا المدراء');
        $bell = Notification::where('user_id', $agent->id)->sole();
        $this->assertStringContainsString('عميل تجريبي اتصل ولم يُرد عليه — النافذة مفتوحة للمراسلة حتى', $bell->body);
        $this->assertStringNotContainsString('16505551234', $bell->body);
        $this->assertSame((int) $agent->id, (int) $conv->assigned_to, 'لا توكيل جديد');

        // إعادة ميتا للحدثين: لا صف ولا رسالة ولا إشعار
        $this->postRawWebhook($this->calls('calls_connect_user.json'))->assertOk();
        $this->postRawWebhook($this->calls('calls_terminate_failed.json', secondsAgo: 4))->assertOk();
        $this->assertSame(1, WaCall::count());
        $this->assertSame(1, WaMessage::count());
        $this->assertSame(1, Notification::where('user_id', $agent->id)->count());
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
        Http::assertNothingSent();
    }

    public function test_without_an_assignee_the_admins_are_notified(): void
    {
        $admin = User::factory()->admin()->create();
        $this->conversation();

        $this->postRawWebhook($this->calls('calls_terminate_failed.json'))->assertOk();

        $this->assertSame(1, WaCall::count(), 'الانتهاء وحده يكفي للسجل');
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', '📞 مكالمة فائتة')->count());
    }

    public function test_a_completed_call_shows_its_duration_without_attention(): void
    {
        $conv = $this->conversation();

        $this->postRawWebhook($this->calls('calls_connect_user.json', secondsAgo: 200))->assertOk();
        $this->postRawWebhook($this->calls('calls_terminate_completed.json', secondsAgo: 2))->assertOk();

        $call = WaCall::sole();
        $this->assertFalse($call->missed);
        $this->assertSame(['completed', 135], [$call->status, $call->duration_seconds]);
        $this->assertNotNull($call->start_time);
        $this->assertSame('📞 مكالمة واردة، مدتها 2:15', WaMessage::sole()->body);
        $this->assertFalse($conv->fresh()->needs_attention);
        $this->assertSame(0, Notification::count());
    }

    public function test_events_for_another_number_or_without_a_caller_are_counted_and_ignored(): void
    {
        $foreign = json_decode($this->calls('calls_connect_user.json'), true);
        $foreign['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = '199999999999999';
        $this->postRawWebhook((string) json_encode($foreign))->assertOk();

        $this->postRawWebhook($this->calls('calls_connect_user.json', ['from' => null, 'id' => 'wacid.FIXTURE.call.3']))->assertOk();
        $this->postRawWebhook($this->calls('calls_terminate_failed.json', ['from' => null, 'id' => 'wacid.FIXTURE.call.4']))->assertOk();

        $this->assertSame(0, WaCall::count());
        $this->assertSame(0, WaConversation::count());
        $this->assertSame(['today' => 3, 'week' => 3], CallEventHandler::rejectedCounts());
    }

    public function test_no_sdp_is_stored_anywhere_and_the_caller_number_never_reaches_the_log(): void
    {
        config(['features.env.inbound_event_log' => true]);
        $this->captureLogs();
        $raw = $this->calls('calls_connect_user.json');
        $this->assertStringContainsString('"sdp"', $raw);

        // الجسم المحفوظ قبل المعالجة نفسه بلا session (والبصمة على الخام)
        [$stored] = WebhookIntake::store($raw);
        $this->assertSame(hash('sha256', $raw), $stored->body_sha256);
        $kept = Crypt::decryptString((string) $stored->getRawOriginal('raw_body'));
        $this->assertStringNotContainsString('sdp', $kept);
        $this->assertStringNotContainsString('session', $kept);
        $this->assertSame('wacid.FIXTURE.call.2', json_decode($kept, true)['entry'][0]['changes'][0]['value']['calls'][0]['id']);
        $stored->delete();

        $this->postRawWebhook($raw)->assertOk();
        $this->postRawWebhook($this->calls('calls_terminate_failed.json', secondsAgo: 3))->assertOk();
        $this->assertSame(WaWebhookEvent::PROCESSED, WaWebhookEvent::orderBy('id')->first()->status);

        foreach (['wa_calls', 'wa_messages', 'wa_conversations', 'wa_webhook_events'] as $table) {
            $dump = json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString('sdp', $dump, $table);
            $this->assertStringNotContainsString('IN IP4', $dump, $table);
        }
        $logged = json_encode(array_column($this->logged, 'context'));
        $this->assertStringNotContainsString('16505551234', $logged);
        $this->assertStringNotContainsString('wacid', $logged);
        $this->assertLogged('info', 'calls.connect');
    }

    public function test_call_rows_never_count_as_pending_nor_enter_the_model_context(): void
    {
        $conv = $this->conversation(['bot_answered_in_id' => 0]);

        $this->postRawWebhook($this->calls('calls_terminate_failed.json'))->assertOk();

        $conv->refresh();
        $this->assertFalse(ReplyState::hasPending($conv));
        $build = new \ReflectionMethod(BotEngine::class, 'buildMessages');
        $context = json_encode($build->invoke(null, $conv, WaBotSetting::current()), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('مكالمة', $context);
    }

    public function test_without_the_flag_calls_stay_counts_only(): void
    {
        config(['features.env.calls_webhook' => false]);

        $this->postRawWebhook($this->calls('calls_connect_user.json'))->assertOk();
        $this->postRawWebhook($this->calls('calls_terminate_failed.json'))->assertOk();

        $this->assertSame(0, WaCall::count());
        $this->assertSame(0, WaMessage::count());
        $this->assertSame(['calls' => 2], WebhookFields::counts());
    }

    public function test_auto_reject_ends_the_ringing_when_no_answer_method_exists(): void
    {
        DB::table('wa_bot_settings')->update(['calls_auto_reject' => true, 'calls_answer_mode' => 'none']);
        $this->conversation();

        $this->postRawWebhook($this->calls('calls_connect_user.json'))->assertOk();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/106540352242922/calls')
            && $r['action'] === 'reject' && $r['call_id'] === 'wacid.FIXTURE.call.2');
        $call = WaCall::sole();
        $this->assertSame('rejected', $call->status);
        $this->assertTrue($call->rejected_by_us);

        $this->postRawWebhook($this->calls('calls_terminate_failed.json', secondsAgo: 3))->assertOk();
        $call->refresh();
        $this->assertSame('rejected', $call->status);
        $this->assertTrue($call->missed, 'المرفوضة آلياً فائتة: تدخّل ومتابعة');
        Http::assertSentCount(1);
    }

    public function test_auto_reject_stays_off_once_an_answer_method_is_declared(): void
    {
        DB::table('wa_bot_settings')->update(['calls_auto_reject' => true, 'calls_answer_mode' => 'webrtc']);
        $this->conversation();

        $this->postRawWebhook($this->calls('calls_connect_user.json'))->assertOk();

        Http::assertNothingSent();
        $this->assertSame('ringing', WaCall::sole()->status);
    }
}
