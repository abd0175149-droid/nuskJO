<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Offer;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\HandoffGuard;
use App\Services\WhatsApp\Llm\AnthropicProvider;
use App\Services\WhatsApp\Llm\GeminiProvider;
use App\Services\WhatsApp\Llm\LlmException;
use App\Services\WhatsApp\ReplyRun;
use App\Services\WhatsApp\VoiceTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * ميزانية الرد (95 ثانية من بداية الوظيفة): كل نداء نموذج بمهلة مما بقي، والحلقة تتوقف قبل أن تتجاوز
 * الوظيفة مهلتها فيُحوَّل العميل لموظف، والخلل العابر يُعاد مرة واحدة، والصوت والبطاقة لا يبدآن بلا وقت.
 */
#[Group('INV-15')]
class ReplyBudgetTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->configureChannel();
        $this->conv = WaConversation::factory()->windowOpen()->create(['bot_enabled' => true]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'كم سعر العمرة؟']);
    }

    protected function tearDown(): void
    {
        ReplyRun::clear();
        parent::tearDown();
    }

    /** تشغيل بقي منه $seconds ثانية (ونصف ثانية زائدة حتى لا يُنقصها التقريب) */
    private function runWith(float $seconds): ReplyRun
    {
        $run = ReplyRun::start($this->conv, 'tok');
        $run->startedAt = microtime(true) - (ReplyRun::BUDGET_SECONDS - $seconds) + 0.5;

        return $run;
    }

    /** @return list<array> خيارات Guzzle لكل نداء Gemini */
    private function fakeGeminiCapturing(array $responses, array &$options): void
    {
        $this->fakeGraph();
        $n = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $r, array $o) use (&$n, &$options, $responses) {
            $options[] = $o;
            $next = $responses[min($n++, count($responses) - 1)];

            return $next instanceof \Closure ? $next() : $next;
        }]);
    }

    private function sentBodies(string $source): array
    {
        return WaMessage::where('direction', 'out')->where('source', $source)->orderBy('id')->pluck('body')->all();
    }

    private function geminiCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage'))->count();
    }

    // ==================== مهلة كل نداء ====================

    public function test_each_model_call_is_bounded_by_what_is_left(): void
    {
        $options = [];
        $this->fakeGeminiCapturing([Http::response($this->geminiResponse('العمرة تبدأ من 650 ديناراً للفرد.'))], $options);

        BotEngine::reply($this->conv, $this->runWith(35));

        $this->assertSame(30, (int) $options[0]['timeout']);          // ما بقي ناقص 5 للإرسال
        $this->assertSame(5, (int) $options[0]['connect_timeout']);
        $this->assertSame(['العمرة تبدأ من 650 ديناراً للفرد.'], $this->sentBodies('bot'));
    }

    public function test_a_fresh_run_caps_the_call_at_forty_seconds(): void
    {
        $options = [];
        $this->fakeGeminiCapturing([Http::response($this->geminiResponse('العمرة تبدأ من 650 ديناراً للفرد.'))], $options);

        BotEngine::reply($this->conv, $this->runWith(ReplyRun::BUDGET_SECONDS));

        $this->assertSame(ReplyRun::CALL_TIMEOUT_SECONDS, (int) $options[0]['timeout']);
    }

    // ==================== نفاد الميزانية ====================

    public function test_the_loop_stops_and_hands_off_when_the_budget_runs_out(): void
    {
        $this->captureLogs();
        $run = $this->runWith(ReplyRun::BUDGET_SECONDS);
        $options = [];
        // أول نداء يطلب أداة ويستهلك الوقت كله تقريباً، فلا دورة ثانية
        $this->fakeGeminiCapturing([function () use ($run) {
            $run->startedAt -= 85;

            return Http::response($this->geminiResponse(['call' => 'get_offers']));
        }], $options);

        $result = BotEngine::reply($this->conv, $run);

        $this->assertSame(1, $this->geminiCalls());
        $this->assertSame('budget', $run->abort);
        $this->assertTrue($result['sent']);
        $this->assertSame([HandoffGuard::confirmation(false)], $this->sentBodies('bot'));

        $conv = $this->conv->fresh();
        $this->assertFalse($conv->bot_enabled);
        $this->assertTrue($conv->needs_attention);
        $this->assertSame('budget', $conv->attention_reason);
        $this->assertSame((int) WaMessage::where('conversation_id', $conv->id)->where('direction', 'in')->max('id'), $conv->bot_answered_in_id);
        $this->assertLogged('warning', 'bot.reply.budget', ['conv_id' => $conv->id, 'count' => 1]);

        // النداء الذي تم يُحسب في الاستهلاك
        $this->assertTrue(WaBotUsage::where('source', 'live')->where('calls', 1)->exists());
    }

    public function test_no_model_call_starts_with_too_little_time(): void
    {
        Http::fake();
        $this->fakeGraph();

        BotEngine::reply($this->conv, $this->runWith(ReplyRun::MIN_TURN_SECONDS - 3));

        $this->assertSame(0, $this->geminiCalls());
        $this->assertSame([HandoffGuard::confirmation(false)], $this->sentBodies('bot'));
        $this->assertSame('budget', $this->conv->fresh()->attention_reason);
    }

    public function test_a_handoff_already_made_is_not_repeated_when_time_runs_out(): void
    {
        $run = $this->runWith(ReplyRun::BUDGET_SECONDS);
        $options = [];
        $this->fakeGeminiCapturing([function () use ($run) {
            $run->startedAt -= 85;

            return Http::response($this->geminiResponse(['call' => 'handoff_to_human', 'args' => ['reason' => 'يريد موظفاً']]));
        }], $options);

        BotEngine::reply($this->conv, $run);

        $this->assertTrue($run->escalated);
        $this->assertSame('handoff', $this->conv->fresh()->attention_reason);
        $this->assertCount(1, $this->sentBodies('bot'));
    }

    // ==================== الخلل العابر ====================

    public function test_a_transient_error_is_retried_once_after_a_pause(): void
    {
        $options = [];
        $this->fakeGeminiCapturing([
            Http::response(['error' => ['message' => 'overloaded']], 503),
            Http::response($this->geminiResponse('العمرة تبدأ من 650 ديناراً للفرد.')),
        ], $options);

        BotEngine::reply($this->conv, $this->runWith(ReplyRun::BUDGET_SECONDS));

        $this->assertSame(2, $this->geminiCalls());
        $this->assertSame(['العمرة تبدأ من 650 ديناراً للفرد.'], $this->sentBodies('bot'));
        Sleep::assertSequence([Sleep::for(ReplyRun::RETRY_DELAY_MS)->milliseconds()]);
    }

    public function test_a_second_transient_error_ends_in_the_fail_safe(): void
    {
        $options = [];
        $this->fakeGeminiCapturing([Http::response(['error' => ['message' => 'overloaded']], 503)], $options);

        BotEngine::reply($this->conv, $this->runWith(ReplyRun::BUDGET_SECONDS));

        $this->assertSame(2, $this->geminiCalls());
        $this->assertSame([], $this->sentBodies('bot'));
        $this->assertCount(1, $this->sentBodies('system'));
    }

    public function test_no_retry_without_enough_time(): void
    {
        $options = [];
        $this->fakeGeminiCapturing([Http::response(['error' => ['message' => 'overloaded']], 503)], $options);

        BotEngine::reply($this->conv, $this->runWith(ReplyRun::RETRY_MIN_SECONDS - 5));

        $this->assertSame(1, $this->geminiCalls());
        $this->assertCount(1, $this->sentBodies('system'));
        Sleep::assertNeverSlept();
    }

    public function test_a_client_error_is_not_retried(): void
    {
        $options = [];
        $this->fakeGeminiCapturing([Http::response(['error' => ['message' => 'API key not valid']], 400)], $options);

        BotEngine::reply($this->conv, $this->runWith(ReplyRun::BUDGET_SECONDS));

        $this->assertSame(1, $this->geminiCalls());
        Sleep::assertNeverSlept();
    }

    public static function statuses(): array
    {
        return [
            // 429 بلا retry-after: خنق عند Gemini، وسقف إنفاق عند Anthropic (LlmErrorClassifierTest)
            '400' => [400, false], '401' => [401, false], '404' => [404, false],
            '500' => [500, true], '502' => [502, true], '503' => [503, true], '504' => [504, true],
        ];
    }

    #[DataProvider('statuses')]
    public function test_providers_mark_only_throttling_and_server_errors_as_transient(int $status, bool $transient): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'x']], $status),
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'x']], $status),
        ]);

        foreach ([new GeminiProvider, new AnthropicProvider] as $provider) {
            try {
                $provider->generate('k', 'm', ['s'], [], []);
                $this->fail('لم يُرمَ استثناء');
            } catch (\RuntimeException $e) {
                $this->assertSame($transient, $e instanceof LlmException && $e->retryable(), $provider->key().' '.$status);
            }
        }
    }

    public function test_anthropic_overload_is_transient(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'Overloaded']], 529)]);

        try {
            (new AnthropicProvider)->generate('k', 'm', ['s'], [], []);
            $this->fail('لم يُرمَ استثناء');
        } catch (LlmException $e) {
            $this->assertTrue($e->retryable());
        }
    }

    public function test_a_connection_failure_is_transient_and_carries_no_url(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::failedConnection('cURL error 28: timed out for https://generativelanguage.googleapis.com/v1beta/models/m:generateContent'),
            'api.anthropic.com/*' => Http::failedConnection('cURL error 7 for https://api.anthropic.com/v1/messages'),
        ]);

        foreach ([new GeminiProvider, new AnthropicProvider] as $provider) {
            try {
                $provider->generate('k', 'm', ['s'], [], [], 7);
                $this->fail('لم يُرمَ استثناء');
            } catch (LlmException $e) {
                $this->assertTrue($e->retryable());
                $this->assertStringNotContainsString('https://', $e->getMessage());
                $this->assertStringNotContainsString('cURL', $e->getMessage());
            }
        }
    }

    // ==================== الصوت ====================

    private function voiceMessage(): WaMessage
    {
        return WaMessage::factory()->inbound()->create([
            'conversation_id' => $this->conv->id, 'msg_type' => 'audio', 'body' => '🎤 رسالة صوتية',
            'payload' => ['audio' => ['id' => '1003383421387256']],
        ]);
    }

    public function test_voice_is_left_untranscribed_without_enough_time(): void
    {
        Http::fake();
        $voice = $this->voiceMessage();

        VoiceTranscriber::run($this->conv, $this->runWith(ReplyRun::VOICE_MIN_SECONDS - 5));

        $voice->refresh();
        $this->assertSame(VoiceTranscriber::FAILED_BODY, $voice->body);
        $this->assertSame('budget', $voice->payload['_media_error']);
        $this->assertTrue($voice->payload['_transcribed']);
        Http::assertNothingSent();
    }

    public function test_transcription_leaves_the_loop_its_share_of_the_budget(): void
    {
        $voice = $this->voiceMessage();
        $timeouts = [];
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'audio/ogg', 'sha256' => hash('sha256', 'MEDIA-BYTES')]),
            'lookaside.fbsbx.com/*' => Http::response('MEDIA-BYTES'),
            'generativelanguage.googleapis.com/*' => function (Request $r, array $o) use (&$timeouts) {
                $timeouts[] = (int) $o['timeout'];

                return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'أريد عمرة']]]]]]);
            },
        ]);

        VoiceTranscriber::run($this->conv, $this->runWith(70));

        $this->assertSame([70 - ReplyRun::VOICE_RESERVE_SECONDS], $timeouts);
        $this->assertSame('🎤 أريد عمرة', $voice->fresh()->body);
    }

    // ==================== بطاقة العرض ====================

    public function test_an_offer_card_is_not_rendered_without_enough_time(): void
    {
        $this->captureLogs();
        $this->fakeGraph();
        $offer = Offer::factory()->ready()->create(['card_path' => null, 'custom_card_path' => null]);
        ReplyRun::bind($this->runWith(ReplyRun::CARD_MIN_SECONDS - 5));

        $out = BotTools::execute('send_offer_card', ['offer_id' => $offer->id], $this->conv);

        $this->assertFalse($out['sent']);
        $this->assertStringContainsString('نصّاً', $out['note']);
        $this->assertLogged('warning', 'bot.card.skipped_budget', ['offer_id' => $offer->id, 'conv_id' => $this->conv->id]);
        Http::assertNothingSent();
    }

    public function test_a_ready_offer_card_is_still_sent_with_little_time(): void
    {
        config(['filesystems.disks.public.url' => 'https://nusk.test/storage']);
        $this->fakeGraph();
        $offer = Offer::factory()->ready()->create(['custom_card_path' => 'offer-cards/test-card.png']);
        ReplyRun::bind($this->runWith(ReplyRun::MIN_TURN_SECONDS));

        $out = BotTools::execute('send_offer_card', ['offer_id' => $offer->id], $this->conv);

        $this->assertTrue($out['sent']);
        Http::assertSent(fn (Request $r) => $r['type'] === 'image');
    }
}
