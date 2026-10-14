<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Jobs\SendOutageNotice;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaLlmIncident;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\HandoffGuard;
use App\Services\WhatsApp\LlmCircuitBreaker as Breaker;
use App\Services\WhatsApp\VoiceTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * وضع التحويل (E12.4/E12.5/E12.8): القاطع مفتوح = لا نداء للنموذج، ورسالة تحويل واحدة يبنيها الكود لكل محادثة،
 * و«تدخّل» وتوكيل وإسكات دون إطفاء البوت؛ والعاصفة لا تُغرق المدراء؛ والوظيفة الفاشلة تُبلغ العميل مرة.
 */
class BotOutageHandoffTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->configureChannel(['breaker_mode' => 'on']);
        $this->admin = User::factory()->admin()->create();
    }

    private function conv(array $attrs = [], string $body = 'كم سعر العمرة؟'): WaConversation
    {
        $conv = WaConversation::factory()->windowOpen()->create($attrs + ['bot_enabled' => true]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => $body]);

        return $conv;
    }

    private function geminiCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage'))->count();
    }

    private function outbound(WaConversation $conv)
    {
        return WaMessage::where('conversation_id', $conv->id)->where('direction', 'out')->get();
    }

    public function test_an_open_breaker_hands_off_without_calling_the_model(): void
    {
        $this->fakeGraph();
        $specialist = $this->staffWith('umrah-desk', ['whatsapp.view', 'whatsapp.reply'], ['umrah'], primary: true);
        Breaker::forceOpen('google', 30, $this->admin->id);
        $before = Notification::count();
        $conv = $this->conversationTagged(['umrah'], ['bot_enabled' => true]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'كم سعر العمرة؟']);

        $this->assertSame('llm_outage', BotEngine::reply($conv)['reason']);

        $this->assertSame(0, $this->geminiCalls());
        $out = $this->outbound($conv);
        $this->assertSame([1, 'system', HandoffGuard::confirmation(false)], [$out->count(), $out[0]->source, $out[0]->body]);
        $conv->refresh();
        $this->assertTrue($conv->needs_attention);
        $this->assertTrue($conv->bot_enabled, 'لا يُطفأ البوت');
        $this->assertTrue($conv->bot_paused_until->gte(Breaker::openUntil('google')));
        $this->assertSame([$specialist->id, 'llm_outage'], [$conv->assigned_to, $conv->attention_reason]);
        $this->assertSame($before, Notification::count(), 'لا إشعار لكل محادثة');
        $this->assertSame('outage', WaBotUsage::sole()->source);
    }

    public function test_more_messages_during_the_outage_do_not_send_a_second_handoff(): void
    {
        $this->fakeGraph();
        Breaker::forceOpen('google', 30, $this->admin->id);
        $conv = $this->conv();
        BotEngine::reply($conv);

        foreach (['مرحبا؟', 'هل من أحد؟'] as $body) {
            WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => $body]);
            BotEngine::reply($conv->fresh());
        }

        $this->assertCount(1, $this->outbound($conv));
    }

    public function test_a_closed_window_sends_nothing_but_still_needs_attention(): void
    {
        $this->fakeGraph();
        Breaker::forceOpen('google', 30, $this->admin->id);
        $conv = $this->conv(['last_inbound_at' => now()->subDays(2)]);

        BotEngine::reply($conv);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com'));
        $this->assertTrue($conv->fresh()->needs_attention);
    }

    public function test_a_storm_of_503_stops_calling_the_model_after_the_threshold(): void
    {
        $this->fakeGraph(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'overloaded']], 503)]);
        $convs = collect(range(1, 5))->map(fn () => $this->conv());

        $calls = [];
        foreach ($convs as $conv) {
            $before = $this->geminiCalls();
            BotEngine::reply($conv);
            $calls[] = $this->geminiCalls() - $before;
        }

        $this->assertSame([2, 2, 2, 0, 0], $calls, 'إعادة داخلية واحدة لكل من الثلاث الأولى، ثم لا نداء');
        foreach ($convs as $conv) {
            $this->assertCount(1, $this->outbound($conv), "محادثة {$conv->id}: رسالة واحدة بالضبط");
        }
        $this->assertSame(3, Notification::where('user_id', $this->admin->id)->count(), 'إخفاقان منفردان ثم إشعار الفتح');
        $this->assertSame(3, WaLlmIncident::sole()->handoffs, 'الثالثة (فتحت القاطع) والرابعة والخامسة');
    }

    public function test_asking_for_a_human_during_the_outage_needs_no_model(): void
    {
        $this->fakeGraph();
        Breaker::forceOpen('google', 30, $this->admin->id);
        $conv = $this->conv([], 'أريد التحدث مع موظف');

        BotEngine::reply($conv);

        $this->assertSame(0, $this->geminiCalls());
        $this->assertFalse($conv->fresh()->bot_enabled, 'مسار التحويل المعتاد');
    }

    public function test_the_admin_assistant_gets_one_fixed_notice_per_incident(): void
    {
        $this->fakeGraph();
        Breaker::forceOpen('google', 30, $this->admin->id);
        $conv = $this->conv([], 'ملخص اليوم');
        WaAdminNumber::factory()->create(['phone' => $conv->phone]);

        BotEngine::reply($conv);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'والآن؟']);
        BotEngine::reply($conv->fresh());

        $this->assertSame(0, $this->geminiCalls());
        $out = $this->outbound($conv);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('المساعد الإداري متوقف مؤقتاً', $out[0]->body);
        $this->assertFalse((bool) $conv->fresh()->needs_attention);
    }

    public function test_voice_waits_for_recovery_while_the_breaker_is_open(): void
    {
        $this->fakeGraph();
        Breaker::forceOpen('google', 30, $this->admin->id);
        $conv = WaConversation::factory()->windowOpen()->create();
        $voice = WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'msg_type' => 'audio', 'body' => '🎤 رسالة صوتية', 'payload' => ['audio' => ['id' => 'm1']]]);

        VoiceTranscriber::run($conv);

        $this->assertSame(0, $this->geminiCalls());
        $this->assertNull(data_get($voice->fresh()->payload, '_transcribed'));
    }

    public function test_shadow_mode_keeps_the_old_fail_safe(): void
    {
        WaBotSetting::current()->forceFill(['breaker_mode' => 'shadow'])->save();
        $this->fakeGraph(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'bad key']], 403)]);
        $conv = $this->conv();

        $this->assertSame('fail-safe', BotEngine::reply($conv)['reason']);
        $this->assertSame(1, WaLlmIncident::where('shadow', true)->count());
    }

    public function test_a_failed_reply_job_queues_one_handoff_notice_and_no_http(): void
    {
        Queue::fake();
        Http::fake();
        $conv = $this->conv();

        (new ProcessWhatsAppReply($conv->id, 'tok'))->failed(new TimeoutExceededException('timed out'));

        $this->assertTrue($conv->fresh()->needs_attention);
        Queue::assertPushed(SendOutageNotice::class, fn ($job) => $job->conversationId === $conv->id);
        Http::assertNothingSent();
    }

    public function test_the_notice_is_sent_once_and_not_after_a_reply(): void
    {
        $this->fakeGraph();
        $conv = $this->conv();

        (new SendOutageNotice($conv->id))->handle();
        (new SendOutageNotice($conv->id))->handle();
        $this->assertCount(1, $this->outbound($conv));
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->count());

        $answered = $this->conv();
        WaMessage::factory()->outbound()->create(['conversation_id' => $answered->id]);
        (new SendOutageNotice($answered->id))->handle();
        $this->assertCount(1, $this->outbound($answered));
    }

    public function test_a_throttled_handoff_is_retried_once_later(): void
    {
        Queue::fake();
        Breaker::forceOpen('google', 30, $this->admin->id);
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131056, 'message' => 'pair rate limit']], 400)]);

        BotEngine::reply($this->conv());

        Queue::assertPushed(SendOutageNotice::class, fn ($job) => $job->notify === false);
    }

    public function test_a_policy_block_is_never_retried(): void
    {
        Queue::fake();
        Breaker::forceOpen('google', 30, $this->admin->id);
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(['error' => ['code' => 368, 'message' => 'blocked']], 400)]);

        BotEngine::reply($this->conv());

        Queue::assertNotPushed(SendOutageNotice::class);
    }
}
