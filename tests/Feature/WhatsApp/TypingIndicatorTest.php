<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\ReadReceipts;
use App\Services\WhatsApp\ReplyRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * مؤشر «يكتب…» (E35): يُرسل مرة في بداية الوظيفة إن كان البوت سيرد فعلاً (قبل التفريغ الصوتي)، ويُجدَّد قبل نداء
 * النموذج إن مرت 20 ثانية، ولا يُجدَّد إن ردّ موظف أثناء التوليد، وفشله لا يمنع الرد. ولا تأخير مصطنع في الكود.
 */
class TypingIndicatorTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const REPLY = 'أهلاً بك، كيف أخدمك؟';

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['features.env.read_receipts' => true]);
        $this->configureChannel(['send_read_receipts' => 'on_reply']);
        $this->conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'مرحبا', 'wamid' => 'wamid.IN.1']);
    }

    protected function tearDown(): void
    {
        ReplyRun::clear();
        parent::tearDown();
    }

    /**
     * Graph: طلب القراءة/المؤشر (status=read) يعيد $read، والرسائل تعيد معرّفاً. Gemini بالترتيب: نص، أو أداة،
     * أو Closure تُنفَّذ قبل الرد (لتقديم الساعة المزيفة) وتعيد أحدهما.
     */
    private function fake(array $turns, ?\Closure $read = null): void
    {
        $n = 0;
        $i = 0;
        Http::fake([
            'graph.facebook.com/*' => function (Request $r) use (&$n, $read) {
                if (($r->data()['status'] ?? null) === 'read') {
                    return $read ? $read($r) : Http::response(['success' => true]);
                }
                $n++;

                return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.OUT.'.$n]]]);
            },
            'generativelanguage.googleapis.com/*' => function () use (&$i, $turns) {
                $turn = $turns[min($i++, count($turns) - 1)];

                return Http::response($this->geminiResponse($turn instanceof \Closure ? $turn() : $turn));
            },
        ]);
    }

    private function runJob(): void
    {
        Cache::put(ProcessWhatsAppReply::debounceKey($this->conv->id), 'tok', now()->addMinutes(5));
        (new ProcessWhatsAppReply($this->conv->id, 'tok'))->handle();
    }

    private function typingRequests(): int
    {
        return Http::recorded(fn (Request $r) => ($r->data()['status'] ?? null) === 'read' && isset($r->data()['typing_indicator']))->count();
    }

    /** ترتيب الطلبات: موضع أول طلب يطابق */
    private function position(\Closure $match): int
    {
        return (int) Http::recorded()->values()->search(fn ($pair) => $match($pair[0]));
    }

    private function botReplies(): array
    {
        return WaMessage::where('direction', 'out')->where('source', 'bot')->pluck('body')->all();
    }

    /** يقدّم ساعة التشغيل والمؤشر $seconds (الساعة الحقيقية microtime، فتُزاح البدايتان) */
    private static function age(int $seconds): void
    {
        $run = ReplyRun::current();
        $run->startedAt -= $seconds;
        if ($run->lastTypingAt !== null) {
            $run->lastTypingAt -= $seconds;
        }
    }

    public function test_one_indicator_before_the_model_then_the_reply_is_sent(): void
    {
        $this->fake([self::REPLY]);

        $this->runJob();

        $this->assertSame(1, $this->typingRequests());
        Http::assertSent(fn (Request $r) => $r->data() === [
            'messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => 'wamid.IN.1', 'typing_indicator' => ['type' => 'text'],
        ]);
        $this->assertLessThan(
            $this->position(fn (Request $r) => str_contains($r->url(), 'generativelanguage')),
            $this->position(fn (Request $r) => ($r->data()['status'] ?? null) === 'read'),
        );
        $this->assertSame([self::REPLY], $this->botReplies());
        // المؤشر يعلّم القراءة أيضاً
        $this->assertSame((int) WaMessage::where('direction', 'in')->max('id'), $this->conv->fresh()->read_receipt_upto_id);
    }

    public function test_the_indicator_covers_voice_transcription(): void
    {
        $this->fake([self::REPLY]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'audio', 'body' => '🎤 رسالة صوتية',
            'wamid' => 'wamid.IN.VOICE', 'payload' => ['audio' => ['id' => 'MEDIA123']]]);

        $this->runJob();

        $typing = $this->position(fn (Request $r) => ($r->data()['status'] ?? null) === 'read');
        $media = $this->position(fn (Request $r) => str_contains($r->url(), 'MEDIA123'));
        $this->assertGreaterThan(0, $media, 'التفريغ طلب الوسائط');
        $this->assertLessThan($media, $typing);
    }

    public static function silentCases(): array
    {
        return ['bot_enabled=false' => ['disabled'], 'paused' => ['paused'], 'window_closed' => ['window'],
            'mode_off' => ['mode_off'], 'flag_off' => ['flag_off'], 'bot_off' => ['bot_off'], 'suspended' => ['suspended']];
    }

    #[DataProvider('silentCases')]
    public function test_no_indicator_when_the_bot_will_not_reply(string $case): void
    {
        $this->fake([self::REPLY]);
        match ($case) {
            'disabled' => $this->conv->update(['bot_enabled' => false]),
            'paused' => $this->conv->update(['bot_paused_until' => now()->addMinutes(20)]),
            'window' => $this->conv->update(['last_inbound_at' => now()->subDays(2)]),
            'mode_off' => WaBotSetting::current()->forceFill(['send_read_receipts' => 'off'])->save(),
            'flag_off' => config(['features.env.read_receipts' => false]),
            'bot_off' => WaBotSetting::current()->forceFill(['enabled' => false])->save(),
            'suspended' => WaBotSetting::current()->forceFill(['wa_suspended' => true])->save(),
        };

        $this->runJob();

        $this->assertSame(0, $this->typingRequests());
    }

    public function test_will_reply_is_pure(): void
    {
        $this->conv->update(['bot_enabled' => false]);

        $this->assertFalse(BotEngine::willReply($this->conv));
        $this->assertFalse((bool) $this->conv->fresh()->needs_attention);
        $this->assertNull($this->conv->fresh()->attention_reason);

        $this->conv->update(['bot_enabled' => true]);
        $this->assertTrue(BotEngine::willReply($this->conv));

        // لا وارد ينتظر بعد آخر رد
        $this->conv->update(['bot_answered_in_id' => WaMessage::where('direction', 'in')->max('id')]);
        $this->assertFalse(BotEngine::willReply($this->conv));
    }

    public function test_a_long_loop_renews_the_indicator_once_before_the_second_call(): void
    {
        // كل دورة تستغرق 25 ثانية مزيفة: الأولى تطلب أداة، والثانية تعيد الرد
        $this->fake([
            function () {
                self::age(25);

                return ['call' => 'get_offers'];
            },
            function () {
                self::age(25);

                return self::REPLY;
            },
        ]);

        $this->runJob();

        $this->assertSame(2, $this->typingRequests());
        $this->assertSame([self::REPLY], $this->botReplies());
    }

    public function test_no_renewal_when_staff_takes_over_during_generation(): void
    {
        $staff = User::factory()->create();
        $this->fake([
            function () use ($staff) {
                self::age(25);
                WaMessage::factory()->fromStaff($staff)->create(['conversation_id' => $this->conv->id, 'body' => 'أنا معك']);

                return ['call' => 'get_offers'];
            },
            self::REPLY,
        ]);

        $this->runJob();

        $this->assertSame(1, $this->typingRequests());
        $this->assertSame([], $this->botReplies(), 'الرد يُسقط بعد استلام الموظف');
    }

    #[DataProvider('failures')]
    public function test_a_failing_indicator_never_blocks_the_reply(string $failure): void
    {
        $this->fake([self::REPLY], $failure === 'http500'
            ? fn () => Http::response(['error' => ['code' => 1, 'message' => 'x']], 500)
            : fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds'));

        $this->runJob();

        // طلب واحد فشل (المهلة لا تُسجَّل في Http::recorded)، ولا تجديد بعده
        $this->assertSame(1, ReadReceipts::failuresToday());
        $this->assertSame([self::REPLY], $this->botReplies());
        $this->assertNull(WaBotSetting::current()->fresh()->wa_last_error);
        $this->assertNull($this->conv->fresh()->read_receipt_upto_id);
    }

    public static function failures(): array
    {
        return ['500' => ['http500'], 'timeout' => ['timeout']];
    }

    public function test_the_admin_assistant_also_shows_the_indicator(): void
    {
        WaAdminNumber::factory()->create(['phone' => $this->conv->phone]);
        $this->conv->update(['bot_enabled' => false]);   // أرقام الإدارة لا تتقيد ببوت العملاء
        $this->fake(['التقرير جاهز']);

        $this->runJob();

        $this->assertSame(1, $this->typingRequests());
        $this->assertSame(['التقرير جاهز'], $this->botReplies());
    }

    public function test_no_indicator_with_less_than_twenty_seconds_of_budget(): void
    {
        $this->fake([self::REPLY]);
        $run = ReplyRun::start($this->conv, 'tok');
        $run->startedAt = microtime(true) - (ReplyRun::BUDGET_SECONDS - 15);

        ReadReceipts::startTyping($this->conv, $run);

        $this->assertSame(0, $this->typingRequests());
        $this->assertNull($run->lastTypingAt);
    }

    public function test_the_internal_switch_turns_the_indicator_off_alone(): void
    {
        config(['whatsapp.read_receipts.typing' => false]);
        $this->fake([self::REPLY]);

        $this->runJob();

        $this->assertSame(0, $this->typingRequests());
        $this->assertSame([self::REPLY], $this->botReplies());
    }

    public function test_there_is_no_artificial_delay_in_the_reply_path(): void
    {
        foreach (['app/Services/WhatsApp/BotEngine.php', 'app/Jobs/ProcessWhatsAppReply.php', 'app/Services/WhatsApp/ReadReceipts.php',
            'app/Services/WhatsApp/VoiceTranscriber.php', 'app/Jobs/SendWhatsAppReadReceipt.php'] as $file) {
            $code = (string) file_get_contents(base_path($file));
            $this->assertDoesNotMatchRegularExpression('/\b(u?sleep|time_nanosleep)\s*\(/', $code, $file);
        }
        // الانتظار الوحيد في المحرّك: إعادة نداء النموذج بعد خلل عابر (E07)، لا محاكاة كتابة
        $engine = (string) file_get_contents(base_path('app/Services/WhatsApp/BotEngine.php'));
        $this->assertSame(1, preg_match_all('/Sleep::/', $engine));
        $this->assertStringNotContainsString('Sleep', (string) file_get_contents(base_path('app/Services/WhatsApp/ReadReceipts.php')));
    }
}
