<?php

namespace App\Jobs;

use App\Models\WaConversation;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\ReplyRun;
use App\Services\WhatsApp\VoiceTranscriber;
use App\Support\AuditActor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

/**
 * دمج الرسائل المتتالية: كل رسالة واردة تسجّل رمزاً وتؤجّل وظيفة ثانيتين.
 * الوظيفة تنفّذ فقط إن كان رمزها هو الأحدث — فثلاث رسائل متتالية تُنتج ردّاً واحداً.
 * المعالجات المتزامنة للمحادثة نفسها تُصَفّ (WithoutOverlapping) ولا تُسقط.
 *
 * الثوابت مرتبطة ويحرسها ReplyTimingInvariantsTest:
 *   الميزانية 95 + الإرسال 25 ≤ المهلة 125 < stopwaitsecs 130 < stop_grace_period 140
 *   والمهلة 125 < القفل 180 < retry_after 240 < retryUntil 900 ≤ عمر رمز الدمج 3600
 */
class ProcessWhatsAppReply implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT = 125;

    /** أطول من المهلة: عامل قُتل لا يترك المحادثة لمعالجتين متوازيتين */
    public const LOCK_SECONDS = 180;

    /** يُحسب عند الدفع، فوظيفة قُتلت تنال إعادة حقيقية بعد retry_after */
    public const RETRY_UNTIL_MINUTES = 15;

    /** أطول من retryUntil ومن أقصى عمر للرد، فغياب الرمز لا يحدث لوظيفة حيّة */
    public const DEBOUNCE_MINUTES = 60;

    /** علامة «قيد المعالجة» يقرؤها المُصالِح؛ أطول قليلاً من المهلة */
    public const RUNNING_SECONDS = 130;

    public int $timeout = self::TIMEOUT;

    /** مهلة منتهية = إعادة لا فشل نهائي (المُصالِح والبوابة يمنعان الرد المزدوج) */
    public bool $failOnTimeout = false;

    public int $maxExceptions = 2;

    public function __construct(
        public int $conversationId,
        public string $token,
    ) {}

    public static function debounceKey(int $convId): string
    {
        return "wa:debounce:{$convId}";
    }

    public static function runningKey(int $convId): string
    {
        return "wa:running:{$convId}";
    }

    /** طابور الردود (laravel-wa)، منفصلاً عن default */
    public static function queueName(): string
    {
        return (string) config('services.whatsapp.reply_queue', 'wa-replies');
    }

    /** يُستدعى من الويبهوك بعد تخزين الرسالة، ومن المُصالِح */
    public static function schedule(int $convId): void
    {
        $token = uniqid('t', true);
        Cache::put(self::debounceKey($convId), $token, now()->addMinutes(self::DEBOUNCE_MINUTES));
        self::dispatch($convId, $token)->onQueue(self::queueName())->delay(now()->addSeconds(2));
    }

    /**
     * إعادة احتياطية: وصل وارد أثناء التوليد ولم يدخل السياق. وظيفته المؤجّلة قائمة عادةً، وهذه تضمنها.
     * متكررة الأمان (الرمز الأحدث يُبطل المنتظرة، والعلامة تمنع الرد المزدوج)، وثلاث مرات في 10 دقائق كحد.
     */
    private static function rerunIfNewer(WaConversation $conv, ReplyRun $run, array $result): void
    {
        if (!$result['sent'] || $run->uptoId <= 0) {
            return;
        }
        $fresh = $conv->fresh();
        $newer = $conv->messages()->where('direction', 'in')->where('msg_type', '!=', 'reaction')->where('id', '>', $run->uptoId)->exists();
        if (!$newer || !$fresh?->botActive() || !$fresh->isWindowOpen()) {
            return;
        }

        $key = "wa:rerun:{$conv->id}";
        Cache::add($key, 0, now()->addMinutes(10));
        if (Cache::increment($key) <= 3) {
            self::schedule($conv->id);
        }
    }

    /** فشل نهائي (بعد المحاولات): الوارد المعلّق يصير «تدخّل»، بلا رسالة آلية للعميل */
    public function failed(?\Throwable $e): void
    {
        $conv = WaConversation::find($this->conversationId);
        \App\Services\WhatsApp\WaLog::error('reply.job_failed', ['conv_id' => $this->conversationId, 'reason' => $e ? $e::class : null]);
        if ($conv && \App\Services\WhatsApp\ReplyState::available() && \App\Services\WhatsApp\ReplyState::hasPending($conv)) {
            \App\Services\WhatsApp\ReplyState::markUnserved($conv, 'job_failed');
        }
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_UNTIL_MINUTES);
    }

    /** معالجة واحدة لكل محادثة في وقت واحد؛ الثانية تُعاد بعد 5 ثوانٍ بدل أن تخرج بصمت */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("wa-conv:{$this->conversationId}"))->releaseAfter(5)->expireAfter(self::LOCK_SECONDS)];
    }

    public function handle(): void
    {
        // request_id يصل وحده مع الوظيفة (Context)؛ ومعرّف المحادثة لكل سطر سجل في هذه المعالجة
        Context::add('conv_id', $this->conversationId);

        // تجاوزته رسالة أحدث؟ اتركه لها. غياب الرمز (انتهى) لا يُسقطها: البوابة تمنع الرد المزدوج
        $current = Cache::get(self::debounceKey($this->conversationId));
        if ($current !== null && $current !== $this->token) {
            return;
        }

        Cache::put(self::runningKey($this->conversationId), time(), self::RUNNING_SECONDS);
        try {
            $conv = WaConversation::find($this->conversationId);
            if ($conv) {
                $run = ReplyRun::start($conv, $this->token);
                // ما يكتبه البوت في سجل التدقيق (طلب تسعير، تحويل) يُنسب إليه وإلى المحادثة
                AuditActor::as('bot', 'conv:' . $conv->id, function () use ($conv, $run) {
                    // الصوتيات تُفرَّغ أولاً، فيقرؤها البوت نصّاً ضمن السياق؛ ومن ميزانية الرد نفسها
                    VoiceTranscriber::run($conv, $run);
                    $result = BotEngine::reply($conv, $run);
                    self::rerunIfNewer($conv, $run, $result);
                });
            }
        } finally {
            Cache::forget(self::runningKey($this->conversationId));
        }
    }
}
