<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * دمج الرسائل المتتالية: الوظيفة لا تستدعي البوت إلا إن كان رمزها هو الأحدث في الكاش.
 *
 * كل حالة تُجهَّز بحيث يرد البوت لو استُدعي (قناة ونموذج مهيّآن، نافذة مفتوحة، آخر رسالة واردة،
 * وGemini وGraph مزيّفان)، والحالة الضابطة الأولى تثبت ذلك. فـ assertNothingSent في الحالات
 * الأخرى يعني أن BotEngine لم يُستدعَ، لا أن الإعداد ناقص.
 */
class ProcessWhatsAppReplyTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const BOT_REPLY = 'أهلاً بك، عروض العمرة تبدأ من 450 ديناراً.';

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
        $this->fakeGraph();
        $this->fakeGemini(self::BOT_REPLY);

        $this->conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->inbound()->create([
            'conversation_id' => $this->conv->id,
            'body' => 'كم سعر العمرة؟',
        ]);
    }

    private function jobWithToken(string $token): ProcessWhatsAppReply
    {
        return new ProcessWhatsAppReply($this->conv->id, $token);
    }

    private function outboundCount(): int
    {
        return WaMessage::where('direction', 'out')->count();
    }

    public function test_job_with_current_token_runs_the_bot_and_clears_its_running_mark(): void
    {
        Cache::put(ProcessWhatsAppReply::debounceKey($this->conv->id), 'tok-current', now()->addMinutes(5));

        $this->jobWithToken('tok-current')->handle();

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://generativelanguage.googleapis.com/'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v25.0/106540352242922/messages');
        $this->assertSame(1, $this->outboundCount());
        $this->assertSame(self::BOT_REPLY, WaMessage::where('direction', 'out')->sole()->body);

        $this->assertNull(Cache::get(ProcessWhatsAppReply::runningKey($this->conv->id)), 'علامة «قيد المعالجة» تُحذف بعد المعالجة');
    }

    #[Group('INV-14')]
    public function test_job_superseded_by_newer_token_does_not_call_the_bot(): void
    {
        Cache::put(ProcessWhatsAppReply::debounceKey($this->conv->id), 'tok-newer', now()->addMinutes(5));

        $this->jobWithToken('tok-older')->handle();

        Http::assertNothingSent();
        $this->assertSame(0, $this->outboundCount());
    }

    #[Group('INV-14')]
    public function test_job_whose_token_expired_still_replies_and_only_once(): void
    {
        // الرمز يعيش 60 دقيقة؛ غيابه لا يُسقط وظيفة حيّة (أُعيدت بعد قتل العامل مثلاً)، والبوابة تمنع الرد الثاني
        $this->jobWithToken('tok-expired')->handle();
        $this->jobWithToken('tok-expired')->handle();

        $this->assertSame(1, $this->outboundCount());
    }

    #[Group('INV-14')]
    public function test_burst_of_scheduled_jobs_produces_a_single_reply(): void
    {
        Queue::fake();

        ProcessWhatsAppReply::schedule($this->conv->id);
        ProcessWhatsAppReply::schedule($this->conv->id);
        ProcessWhatsAppReply::schedule($this->conv->id);

        $jobs = Queue::pushed(ProcessWhatsAppReply::class);
        $this->assertCount(3, $jobs);
        $this->assertCount(3, $jobs->pluck('token')->unique());
        $this->assertSame(
            $jobs->last()->token,
            Cache::get(ProcessWhatsAppReply::debounceKey($this->conv->id)),
        );
        foreach ($jobs as $job) {
            $this->assertSame($this->conv->id, $job->conversationId);
            $this->assertNotNull($job->delay);
        }

        foreach ($jobs as $job) {
            $job->handle();
        }

        Http::assertSentCount(2); // طلب Gemini واحد ورسالة Graph واحدة
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com')
            && $r['text']['body'] === self::BOT_REPLY);
        $this->assertSame(1, $this->outboundCount());
    }

    #[Group('INV-15')]
    public function test_job_waits_while_the_conversation_is_being_processed(): void
    {
        Cache::put(ProcessWhatsAppReply::debounceKey($this->conv->id), 'tok-current', now()->addMinutes(5));
        $held = Cache::lock('laravel-queue-overlap:'.ProcessWhatsAppReply::class.":wa-conv:{$this->conv->id}", ProcessWhatsAppReply::LOCK_SECONDS);
        $this->assertTrue($held->get());

        // عبر الطابور (الوسيط يعمل): تُعاد إلى الطابور بدل الخروج بصمت، ولا ترد الآن
        dispatch_sync($this->jobWithToken('tok-current'));

        Http::assertNothingSent();
        $this->assertSame(0, $this->outboundCount());

        $held->release();
        dispatch_sync($this->jobWithToken('tok-current'));
        $this->assertSame(1, $this->outboundCount());
    }
}
