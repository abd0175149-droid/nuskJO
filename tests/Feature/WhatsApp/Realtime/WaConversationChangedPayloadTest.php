<?php

namespace Tests\Feature\WhatsApp\Realtime;

use App\Events\WaConversationChanged;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Realtime\InboxBroadcaster;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CapturesBroadcasts;
use Tests\TestCase;

/**
 * حمولة حدث الصندوق (E28): {c, k} وحدهما، على قنوات user.N الخاصة، باسم wa.changed. لا هاتف ولا اسم ولا نص.
 */
class WaConversationChangedPayloadTest extends TestCase
{
    use CapturesBroadcasts;
    use RefreshDatabase;

    public function test_the_payload_is_the_conversation_id_and_kind_only(): void
    {
        $event = new WaConversationChanged(7, 'message', [3, 5, 3]);

        $this->assertSame(['c' => 7, 'k' => 'message'], $event->broadcastWith());
        $this->assertSame('wa.changed', $event->broadcastAs());
        $channels = $event->broadcastOn();
        $this->assertContainsOnlyInstancesOf(PrivateChannel::class, $channels);
        $this->assertSame(['private-user.3', 'private-user.5'], array_map(fn ($c) => $c->name, $channels));
    }

    public function test_the_wire_payload_carries_no_phone_name_or_text(): void
    {
        $this->captureBroadcasts();
        $this->enableInboxRealtime();
        $admin = User::factory()->admin()->create();
        $conversation = WaConversation::factory()->windowOpen()->create(['display_name' => 'Sheena Nelson', 'wa_profile_name' => 'Sheena', 'last_message_preview' => 'Does it come in another color?']);
        WaMessage::factory()->create(['conversation_id' => $conversation->id, 'body' => 'Does it come in another color?']);

        app(InboxBroadcaster::class)->touch($conversation->id, 'message');

        $this->assertCount(1, $this->inboxBroadcasts());
        [$sent] = $this->inboxBroadcasts();
        $this->assertSame(['c' => $conversation->id, 'k' => 'message'], $sent['payload']);
        $this->assertSame(["private-user.{$admin->id}"], $sent['channels']);

        $wire = json_encode($sent, JSON_UNESCAPED_UNICODE);
        foreach ([$conversation->phone, 'Sheena', 'another color'] as $secret) {
            $this->assertStringNotContainsString($secret, $wire);
        }
    }

    public function test_only_the_three_kinds_are_broadcast(): void
    {
        $this->captureBroadcasts();
        $this->enableInboxRealtime();
        User::factory()->admin()->create();
        $conversation = WaConversation::factory()->create();

        app(InboxBroadcaster::class)->touch($conversation->id, 'typing');
        $this->assertSame([], $this->inboxBroadcasts());

        foreach (WaConversationChanged::KINDS as $kind) {
            app(InboxBroadcaster::class)->touch($conversation->id, $kind);
        }
        $this->assertSame(['message', 'status', 'meta'], array_map(fn ($b) => $b['payload']['k'], $this->inboxBroadcasts()));
    }
}
