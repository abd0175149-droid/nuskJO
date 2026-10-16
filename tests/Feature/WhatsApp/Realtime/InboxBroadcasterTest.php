<?php

namespace Tests\Feature\WhatsApp\Realtime;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Ops\OpsHealth;
use App\Services\WhatsApp\Realtime\InboxBroadcaster;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CapturesBroadcasts;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * InboxBroadcaster (E28): حدث واحد لكل محادثة في الطلب بأعلى نوع، وبعد إرسال الرد. لا يُفشل أي طلب: Reverb متعطّل
 * يفتح قاطعاً 60 ثانية لا يُحاوَل البث خلالها. والعلم مطفأ لا يُطلق شيئاً.
 */
class InboxBroadcasterTest extends TestCase
{
    use CapturesBroadcasts, CapturesLogs, ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        $this->admin = User::factory()->admin()->create();
    }

    private function customer(): WaConversation
    {
        return WaConversation::firstOrCreate(['phone' => '16505551234'], ['wa_phone' => '16505551234', 'last_inbound_at' => now()]);
    }

    private function outbound(string $wamid): WaMessage
    {
        return WaMessage::factory()->outbound()->create(['conversation_id' => $this->customer()->id, 'wamid' => $wamid, 'status' => 'sent']);
    }

    public function test_a_webhook_batch_gives_one_event_per_conversation_after_the_response(): void
    {
        $this->captureBroadcasts();
        $this->enableInboxRealtime();
        $this->outbound('wamid.FIXTURE.out.1');
        $this->outbound('wamid.FIXTURE.out.2');
        $atResponse = null;
        Event::listen(RequestHandled::class, function () use (&$atResponse) {
            $atResponse = count($this->inboxBroadcasts());
        });

        // رسالتان وحالتان للمحادثة نفسها في حمولة واحدة
        $this->postMetaWebhook('batch_mixed.json')->assertOk();

        $this->assertSame(0, $atResponse, 'لا بث قبل إرسال الرد');
        $this->assertCount(1, $this->inboxBroadcasts());
        $this->assertSame(['c' => $this->customer()->id, 'k' => 'message'], $this->inboxBroadcasts()[0]['payload']);
        $this->assertSame([$this->admin->id], $this->recipientsOf($this->customer()->id));
    }

    public function test_touches_in_one_unit_merge_to_the_highest_kind_and_the_union_of_extra_recipients(): void
    {
        $this->captureBroadcasts();
        $this->enableInboxRealtime();
        $other = User::factory()->create();
        $conversation = $this->customer();

        DB::transaction(function () use ($conversation, $other) {
            $rt = app(InboxBroadcaster::class);
            $rt->touch($conversation->id, 'status');
            $rt->touch($conversation->id, 'message', [$other->id]);
            $rt->touch($conversation->id, 'meta');
            $this->assertSame([], $this->inboxBroadcasts(), 'لا بث قبل الاعتماد');
        });

        $this->assertCount(1, $this->inboxBroadcasts());
        $this->assertSame('message', $this->inboxBroadcasts()[0]['payload']['k']);
        $this->assertSame([$this->admin->id, $other->id], $this->recipientsOf($conversation->id));
    }

    public function test_a_rolled_back_change_is_not_broadcast(): void
    {
        $this->captureBroadcasts();
        $this->enableInboxRealtime();
        $conversation = $this->customer();

        try {
            DB::transaction(function () use ($conversation) {
                app(InboxBroadcaster::class)->touch($conversation->id, 'meta');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->inboxBroadcasts());
    }

    public function test_with_the_flag_off_nothing_is_broadcast(): void
    {
        $this->captureBroadcasts();
        $this->enableInboxRealtime(false);

        $this->postMetaWebhook('text.json')->assertOk();
        $this->actingAs($this->admin)->postJson("/api/whatsapp/conversations/{$this->customer()->id}/resolve")->assertOk();

        $this->assertSame(0, $this->broadcastAttempts);
        $this->assertSame([], app(InboxBroadcaster::class)->pending());
    }

    public function test_a_failing_broadcaster_never_fails_the_webhook_and_opens_the_breaker(): void
    {
        $this->captureBroadcasts(failing: true);
        $this->enableInboxRealtime();
        $this->captureLogs();

        $started = microtime(true);
        $this->postMetaWebhook('text.json')->assertOk()->assertExactJson(['ok' => true]);
        $this->assertLessThan(5, microtime(true) - $started);

        $this->assertSame(1, $this->broadcastAttempts);
        $this->assertTrue(OpsHealth::store()->has(InboxBroadcaster::BREAKER));
        $this->assertLogged('warning', 'rt.broadcast_failed', ['exception' => 'BroadcastException', 'conv_id' => $this->customer()->id]);
        $this->assertStringNotContainsString('reverb unreachable', json_encode($this->logged));

        // خلال القاطع: تغيّر حقيقي في المحادثة، ولا محاولة، والطلب ينجح
        $this->actingAs($this->admin)->postJson("/api/whatsapp/conversations/{$this->customer()->id}/toggle-bot", ['enabled' => false])->assertOk();
        $this->assertFalse($this->customer()->bot_enabled);
        $this->assertSame(1, $this->broadcastAttempts);

        // بعد 60 ثانية: محاولة جديدة
        $this->travel(61)->seconds();
        $this->actingAs($this->admin)->postJson("/api/whatsapp/conversations/{$this->customer()->id}/toggle-bot", ['enabled' => true])->assertOk();
        $this->assertSame(2, $this->broadcastAttempts);
    }

    public function test_a_conversation_nobody_sees_is_skipped(): void
    {
        $this->captureBroadcasts();
        $this->enableInboxRealtime();
        $this->admin->forceFill(['is_active' => false])->save();

        app(InboxBroadcaster::class)->touch($this->customer()->id, 'message');

        $this->assertSame(0, $this->broadcastAttempts);
    }
}
