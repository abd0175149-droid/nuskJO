<?php

namespace Tests\Feature\WhatsApp;

use App\Events\NewNotification;
use App\Events\WhatsApp\WaMessageFailed;
use App\Events\WhatsApp\WaMessageSent;
use App\Events\WhatsApp\WaMessageStatusChanged;
use App\Events\WhatsApp\WaMessageStored;
use App\Jobs\ProcessWhatsAppReply;
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
            WaMessageStatusChanged::class]);
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

    public function test_a_rejection_from_the_gateway_fires_failed_from_the_send(): void
    {
        $this->fakeEvents();
        $this->fakeGraph(['nuskjo-openwa:2785/*/messages/send-text' => Http::response(['statusCode' => 400, 'message' => 'Number is not registered on WhatsApp'], 400)]);
        $conv = WaConversation::factory()->windowOpen()->create();

        $this->assertFalse($this->send($conv)['ok']);

        $row = WaMessage::sole();
        Event::assertDispatched(WaMessageFailed::class, fn ($e) => $e->messageId === $row->id && $e->origin === 'send'
            && $e->scope === 'recipient' && $e->metaCode === null && $e->category === 'quality' && $e->source === 'system');
        Event::assertNotDispatched(WaMessageSent::class);
    }

    public function test_a_lock_before_the_gateway_and_an_unconfirmed_timeout_fire_nothing(): void
    {
        $this->fakeEvents();
        $this->fakeGraph(['nuskjo-openwa:2785/*/messages/send-text' => Http::failedConnection('cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received')]);
        $this->testChannel()->forceFill(['paused_until' => now()->addHour(), 'pause_reason' => 'manual'])->save();
        \App\Models\WaChannel::forgetCache();
        $paused = WaConversation::factory()->windowOpen()->create();
        $this->assertFalse($this->send($paused)['ok']);
        $this->assertSame([], $this->gatewaySends(), 'القفل قبل البوابة');

        $this->testChannel()->forceFill(['paused_until' => null, 'pause_reason' => null])->save();
        \App\Models\WaChannel::forgetCache();
        config(['features.env.send_ledger' => true]);
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
