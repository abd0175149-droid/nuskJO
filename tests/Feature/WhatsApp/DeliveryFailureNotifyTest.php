<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\MetaErrorCatalog;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * «لم تصل رسالتك» (E21): إشعار واحد للمرسل من مسار واحد (المستمع مع wa_event_bus، أو مسار E18 المباشر بدونه)،
 * لأسباب تخص العميل أو الرسالة وحدها، ولا يتكرر بإعادة الويبهوك. رسالة النظام التي رفضتها ميتا فوراً تصل لموكَّل المحادثة.
 */
class DeliveryFailureNotifyTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    /** wamid حمولة status_failed_131047.json */
    private const WAMID = 'wamid.HBgLMTY1MDU1NTEyMzQVAgARGBI4NjAzMUVGQzhCN0UxMTc1NkQA';

    private User $staff;

    private User $admin;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        config(['features.env.meta_error_v2' => true]);
        $this->admin = User::factory()->admin()->create();
        $this->staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply'], 'desk')->create();
        $this->conv = WaConversation::factory()->windowOpen()->create(['display_name' => 'عميل تجريبي', 'assigned_to' => $this->staff->id]);
    }

    private function staffMessage(string $wamid = self::WAMID): WaMessage
    {
        return WaMessage::factory()->fromStaff($this->staff)->create(['conversation_id' => $this->conv->id, 'wamid' => $wamid, 'body' => 'نص سري للموظف']);
    }

    private function failedStatus(string $wamid, int $code, int $ts = 1760425980): string
    {
        return (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '102290129340398', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => self::TEST_PHONE_NUMBER_ID],
            'statuses' => [['id' => $wamid, 'status' => 'failed', 'timestamp' => (string) $ts, 'recipient_id' => '16505551234', 'errors' => [['code' => $code, 'title' => 'x']]]],
        ]]]]]]);
    }

    private function bells(User $user, string $title = '⚠️ لم تصل رسالتك')
    {
        return Notification::where('user_id', $user->id)->where('title', $title)->get();
    }

    public static function statusPaths(): array
    {
        return ['legacy status path' => [false], 'atomic status path' => [true]];
    }

    #[DataProvider('statusPaths')]
    public function test_with_the_bus_the_sender_gets_one_notice_and_a_replay_adds_nothing(bool $atomic): void
    {
        config(['features.env.wa_event_bus' => true, 'features.env.delivery_status_v2' => $atomic]);
        $this->staffMessage();

        $this->postMetaWebhook('status_failed_131047.json')->assertOk();
        $this->postMetaWebhook('status_failed_131047.json')->assertOk();

        $bells = $this->bells($this->staff);
        $this->assertCount(1, $bells);
        $meaning = MetaErrorCatalog::classify(131047);
        $this->assertStringContainsString($meaning['message'], $bells[0]->body);
        $this->assertStringContainsString($meaning['action'], $bells[0]->body);
        $this->assertStringContainsString('عميل تجريبي', $bells[0]->body);
        $this->assertStringNotContainsString('نص سري', $bells[0]->body);
        $this->assertSame("/whatsapp/inbox?conv={$this->conv->id}", $bells[0]->action_url);
    }

    public function test_without_the_bus_only_the_direct_path_notifies(): void
    {
        $this->staffMessage();

        $this->postMetaWebhook('status_failed_131047.json')->assertOk();

        $this->assertCount(1, $this->bells($this->staff));
    }

    public function test_a_channel_wide_failure_is_left_to_the_channel_alerts(): void
    {
        config(['features.env.wa_event_bus' => true]);
        $this->staffMessage('wamid.E21.CHANNEL');

        $this->postRawWebhook($this->failedStatus('wamid.E21.CHANNEL', 190))->assertOk();

        $this->assertSame('failed', WaMessage::sole()->status);
        $this->assertCount(0, $this->bells($this->staff));
    }

    public function test_two_failures_in_one_conversation_within_minutes_send_one_notice(): void
    {
        config(['features.env.wa_event_bus' => true]);
        $this->staffMessage('wamid.E21.A');
        $this->staffMessage('wamid.E21.B');

        $this->postRawWebhook($this->failedStatus('wamid.E21.A', 131026))->assertOk();
        $this->postRawWebhook($this->failedStatus('wamid.E21.B', 131026))->assertOk();

        $this->assertCount(1, $this->bells($this->staff));
    }

    public function test_a_staff_send_rejected_on_the_spot_is_not_notified_again(): void
    {
        config(['features.env.wa_event_bus' => true]);
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(json_decode((string) file_get_contents($this->fixturePath('error_131026.json')), true), 400)]);

        $res = WhatsAppChannel::sendText($this->conv, 'مرحباً', 'staff', $this->staff->id, sendKey: SendKey::staff((string) \Illuminate\Support\Str::uuid()));

        $this->assertFalse($res['ok'], 'المرسل يرى السبب في المنشئ');
        $this->assertCount(0, $this->bells($this->staff));
    }

    public function test_a_system_message_rejected_on_the_spot_goes_to_the_assignee_or_the_admins(): void
    {
        config(['features.env.wa_event_bus' => true]);
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(json_decode((string) file_get_contents($this->fixturePath('error_131026.json')), true), 400)]);

        WhatsAppChannel::sendText($this->conv, 'تم إيقاف الرسائل التسويقية.', 'system', sendKey: SendKey::optout('wamid.IN.1'));
        $orphanConv = WaConversation::factory()->windowOpen()->create();
        WhatsAppChannel::sendText($orphanConv, 'تم إيقاف الرسائل التسويقية.', 'system', sendKey: SendKey::optout('wamid.IN.2'));

        $this->assertCount(1, $this->bells($this->staff, '⚠️ لم تصل رسالة النظام'));
        $this->assertCount(1, $this->bells($this->admin, '⚠️ لم تصل رسالة النظام'));
    }

    public function test_a_failed_bot_reply_is_left_to_the_attention_flag(): void
    {
        config(['features.env.wa_event_bus' => true]);
        WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'wamid' => self::WAMID]);

        $this->postMetaWebhook('status_failed_131047.json')->assertOk();

        $this->assertCount(0, $this->bells($this->staff));
        $this->assertSame('send_failed', $this->conv->fresh()->attention_reason);
    }
}
