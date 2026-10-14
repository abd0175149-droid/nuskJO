<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الحداثة على وقت ميتا: رسالة متأخرة لا يرد عليها البوت بل موظف؛ والنافذة والمعاينة لا تتراجعان؛
 * وحالات التسليم تتقدم ولا تتراجع؛ ورسالة بلا رقم مرسل لا تضيع بصمت.
 */
class FreshnessGateTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
    }

    private function inbound(string $wamid, int $timestamp, string $text = 'مرحبا', array $extra = []): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '102290129340398', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => self::TEST_PHONE_NUMBER_ID],
            'contacts' => [['profile' => ['name' => 'Sheena Nelson'], 'wa_id' => '16505551234']],
            'messages' => [array_merge(['from' => '16505551234', 'id' => $wamid, 'timestamp' => (string) $timestamp, 'type' => 'text', 'text' => ['body' => $text]], $extra)],
        ]]]]]];
    }

    public function test_meta_time_is_stored_and_the_window_does_not_move_back(): void
    {
        $recent = now()->subMinutes(2)->timestamp;
        WhatsAppInbox::handleWebhook($this->inbound('wamid.NEW', $recent, 'الأحدث'));
        WhatsAppInbox::handleWebhook($this->inbound('wamid.OLD', now()->subHours(3)->timestamp, 'أقدم أعادتها ميتا'));

        $conv = WaConversation::sole();
        $this->assertSame($recent, $conv->last_inbound_at->timestamp);
        $this->assertSame('الأحدث', $conv->last_message_preview);
        $this->assertSame(2, $conv->unread_count);
        $this->assertSame(now()->subHours(3)->timestamp, WaMessage::where('wamid', 'wamid.OLD')->sole()->meta_at->timestamp);
    }

    public function test_a_late_message_goes_to_staff_instead_of_the_bot(): void
    {
        Http::fake();
        WhatsAppInbox::handleWebhook($this->inbound('wamid.LATE', now()->subMinutes(30)->timestamp));
        $conv = WaConversation::sole();
        $conv->update(['last_inbound_at' => now()]);   // نافذة مفتوحة، والرسالة نفسها متأخرة

        $this->assertSame('stale', BotEngine::reply($conv->fresh())['reason']);

        $this->assertSame('stale', $conv->fresh()->attention_reason);
        Http::assertNothingSent();
    }

    public function test_the_age_limit_comes_from_settings(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $this->actingAs($owner)->put('/whatsapp/settings', ['max_reply_age_minutes' => 1])->assertSessionHasErrors('max_reply_age_minutes');
        $this->actingAs($owner)->put('/whatsapp/settings', ['max_reply_age_minutes' => 45])->assertSessionHasNoErrors();

        $this->assertSame(45, (int) WaBotSetting::current()->max_reply_age_minutes);
    }

    public function test_delivery_statuses_only_move_forward_and_failed_is_final(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $out = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'wamid' => 'wamid.S1', 'status' => 'sent']);
        $status = fn (string $s) => WhatsAppInbox::handleWebhook(['object' => 'whatsapp_business_account', 'entry' => [['id' => '102290129340398', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => self::TEST_PHONE_NUMBER_ID],
            'statuses' => [['id' => 'wamid.S1', 'status' => $s, 'timestamp' => (string) now()->timestamp, 'recipient_id' => '16505551234']],
        ]]]]]]);

        $status('read');
        $status('delivered');   // وصلت متأخرة
        $this->assertSame('read', $out->fresh()->status);
        $this->assertSame(['read', 'delivered'], array_column($out->fresh()->payload['statusHistory'], 'status'));
    }

    public function test_a_failed_bot_reply_flags_the_conversation(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id]);
        WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'wamid' => 'wamid.F1']);

        WhatsAppInbox::handleWebhook(['object' => 'whatsapp_business_account', 'entry' => [['id' => '102290129340398', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => self::TEST_PHONE_NUMBER_ID],
            'statuses' => [['id' => 'wamid.F1', 'status' => 'failed', 'timestamp' => (string) now()->timestamp, 'recipient_id' => '16505551234',
                'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]],
        ]]]]]]);

        $this->assertSame('send_failed', $conv->fresh()->attention_reason);
    }

    public function test_a_message_without_a_sender_number_alerts_admins_once_an_hour(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->inbound('wamid.NOFROM', now()->timestamp, 'سري', ['from_user_id' => 'US.1234567890']);
        unset($payload['entry'][0]['changes'][0]['value']['messages'][0]['from']);

        WhatsAppInbox::handleWebhook($payload);
        WhatsAppInbox::handleWebhook($payload);

        $this->assertSame(0, WaMessage::count());
        $this->assertSame(1, Notification::where('user_id', $admin->id)->count());
        $this->assertSame(2, Cache::get('wa:nofrom:count:'.now()->format('Ymd')));
    }
}
