<?php

namespace Tests\Feature\WhatsApp;

use App\Events\NewNotification;
use App\Events\WhatsApp\ChannelHealthChanged;
use App\Events\WhatsApp\WaMessageFailed;
use App\Events\WhatsApp\WaMessageSent;
use App\Events\WhatsApp\WaMessageStatusChanged;
use App\Events\WhatsApp\WaMessageStored;
use App\Jobs\ProcessWhatsAppReply;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\EventBus;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\PatchesWhatsAppSettings;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * ناقل الأحداث الداخلي (E21): الأحداث تُطلق بعد الاعتماد وعند تغيّر فعلي وحده، ومستمع يرمي لا يمس الويبهوك ولا
 * الإرسال ولا جدولة رد البوت. رفض الأقفال قبل ميتا والمهلة «غير المؤكدة» لا تطلق شيئاً.
 */
class EventBusTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use PatchesWhatsAppSettings;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
    }

    private function fakeEvents(): void
    {
        Event::fake([NewNotification::class, WaMessageStored::class, WaMessageSent::class, WaMessageFailed::class,
            WaMessageStatusChanged::class, ChannelHealthChanged::class]);
    }

    private function send(WaConversation $conv, string $key = 'wamid.IN.1'): array
    {
        return WhatsAppChannel::sendText($conv, 'مرحباً', 'system', sendKey: SendKey::optout($key));
    }

    public function test_a_stored_inbound_message_fires_once_and_never_for_a_duplicate(): void
    {
        $this->fakeEvents();

        $this->postMetaWebhook('text.json')->assertOk();
        $this->postMetaWebhook('text.json')->assertOk();

        Event::assertDispatchedTimes(WaMessageStored::class, 1);
        $row = WaMessage::sole();
        Event::assertDispatched(WaMessageStored::class, fn ($e) => $e->messageId === $row->id && $e->conversationId === (int) $row->conversation_id && $e->msgType === 'text');
    }

    public function test_a_reaction_is_a_stored_message_too(): void
    {
        $this->fakeEvents();

        $this->postMetaWebhook('reaction.json')->assertOk();

        Event::assertDispatched(WaMessageStored::class, fn ($e) => $e->msgType === 'reaction');
    }

    public function test_an_accepted_send_fires_sent_on_both_paths(): void
    {
        $this->fakeEvents();
        $this->fakeGraph();
        $conv = WaConversation::factory()->windowOpen()->create();

        $this->send($conv, 'wamid.IN.1');
        config(['features.env.send_ledger' => true]);
        $this->send($conv, 'wamid.IN.2');

        Event::assertDispatchedTimes(WaMessageSent::class, 2);
        Event::assertDispatched(WaMessageSent::class, fn ($e) => $e->source === 'system' && $e->staffId === null && $e->conversationId === $conv->id);
        Event::assertNotDispatched(WaMessageFailed::class);
    }

    public function test_a_rejection_from_meta_fires_failed_from_the_send(): void
    {
        $this->fakeEvents();
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(json_decode((string) file_get_contents($this->fixturePath('error_131026.json')), true), 400)]);
        $conv = WaConversation::factory()->windowOpen()->create();

        $this->assertFalse($this->send($conv)['ok']);

        $row = WaMessage::sole();
        Event::assertDispatched(WaMessageFailed::class, fn ($e) => $e->messageId === $row->id && $e->origin === 'send'
            && $e->scope === 'recipient' && $e->metaCode === 131026 && $e->category === 'caller' && $e->source === 'system');
        Event::assertNotDispatched(WaMessageSent::class);
    }

    public function test_a_lock_before_meta_and_an_unconfirmed_timeout_fire_nothing(): void
    {
        $this->fakeEvents();
        $closed = WaConversation::factory()->windowClosed()->create();
        $this->assertFalse($this->send($closed)['ok']);

        config(['features.env.send_ledger' => true]);
        Http::fake(['graph.facebook.com/*' => Http::failedConnection('cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received')]);
        $open = WaConversation::factory()->windowOpen()->create();
        $this->assertTrue((bool) ($this->send($open, 'wamid.IN.9')['unknown'] ?? false));

        Event::assertNotDispatched(WaMessageSent::class);
        Event::assertNotDispatched(WaMessageFailed::class);
    }

    public function test_listeners_hear_nothing_before_the_commit_and_nothing_after_a_rollback(): void
    {
        $seen = [];
        Event::listen(WaMessageStored::class, function (WaMessageStored $e) use (&$seen) {
            $seen[] = $e->messageId;
        });

        try {
            DB::transaction(function () {
                EventBus::emit(new WaMessageStored(1, 1, 'text'));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame([], $seen);

        DB::transaction(function () use (&$seen) {
            EventBus::emit(new WaMessageStored(2, 1, 'text'));
            $this->assertSame([], $seen, 'لا شيء قبل الاعتماد');
        });
        $this->assertSame([2], $seen);
    }

    public function test_a_throwing_listener_never_fails_the_webhook_or_the_reply(): void
    {
        config(['features.env.wa_event_bus' => true]);
        $this->captureLogs();
        Event::listen(WaMessageStored::class, function () {
            throw new \RuntimeException('listener exploded with customer text');
        });

        $this->postMetaWebhook('text.json')->assertOk();

        $row = WaMessage::sole();
        Queue::assertPushed(ProcessWhatsAppReply::class);
        $this->assertLogged('error', 'wa.listener_failed', ['event' => 'WaMessageStored', 'type' => 'RuntimeException',
            'conv_id' => (int) $row->conversation_id, 'message_id' => $row->id]);
        $this->assertStringNotContainsString('customer text', json_encode($this->logged));
    }

    public function test_meta_and_a_manager_changing_the_channel_fire_channel_health_changed(): void
    {
        $this->fakeEvents();
        User::factory()->admin()->create();

        $this->postMetaWebhook('account_update_ban.json')->assertOk();
        Event::assertDispatched(ChannelHealthChanged::class, fn ($e) => [$e->from, $e->to, $e->source, $e->reasonCode]
            === [ChannelHealthChanged::AVAILABLE, ChannelHealthChanged::SUSPENDED_AUTO, 'meta_event', 'DISABLED_UPDATE']);

        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        $this->killSwitch($owner, 'off', ['acknowledge' => true])->assertOk();
        $this->killSwitch($owner, 'on')->assertOk();

        Event::assertDispatched(ChannelHealthChanged::class, fn ($e) => [$e->from, $e->to, $e->source, $e->reasonCode]
            === [ChannelHealthChanged::SUSPENDED_AUTO, ChannelHealthChanged::AVAILABLE, 'manual', 'meta_event']);
        Event::assertDispatched(ChannelHealthChanged::class, fn ($e) => [$e->from, $e->to, $e->source]
            === [ChannelHealthChanged::AVAILABLE, ChannelHealthChanged::SUSPENDED_MANUAL, 'manual']);
        Event::assertDispatchedTimes(ChannelHealthChanged::class, 3);

        // حدث الحظر مكرراً والقناة موقوفة: لا تغيّر فعلي ولا حدث
        $this->postMetaWebhook('account_update_ban.json')->assertOk();
        Event::assertDispatchedTimes(ChannelHealthChanged::class, 3);
    }

    public function test_an_automatic_suspension_from_a_meta_code_fires_once(): void
    {
        $this->fakeEvents();
        config(['features.env.meta_error_v2' => true]);
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(['error' => ['code' => 368, 'message' => 'Temporarily blocked for policies violations',
            'type' => 'OAuthException', 'fbtrace_id' => 'AxE21Blocked000000AA']], 400)]);
        $conv = WaConversation::factory()->windowOpen()->create();

        $this->send($conv);

        Event::assertDispatched(ChannelHealthChanged::class, fn ($e) => [$e->to, $e->source, $e->reasonCode] === [ChannelHealthChanged::SUSPENDED_AUTO, 'meta_event', '368']);
        Event::assertDispatchedTimes(ChannelHealthChanged::class, 1);
    }

    public function test_the_legacy_status_path_fires_the_same_events_on_real_change_only(): void
    {
        $this->fakeEvents();
        $conv = WaConversation::factory()->windowOpen()->create();
        $out = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'wamid' => 'wamid.HBgLMTY1MDU1NTEyMzQVAgARGBI0MkZERjYwREJFODBFQTIxNDEA']);

        $this->postMetaWebhook('status_read.json')->assertOk();
        $this->postMetaWebhook('status_delivered.json')->assertOk();

        Event::assertDispatchedTimes(WaMessageStatusChanged::class, 1);
        Event::assertDispatched(WaMessageStatusChanged::class, fn ($e) => $e->messageId === $out->id && $e->status === 'read' && $e->metaTs === 1760425860);
    }
}
