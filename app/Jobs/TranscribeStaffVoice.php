<?php

namespace App\Jobs;

use App\Models\WaBotSetting;
use App\Models\WaBotUsage;
use App\Models\WaMessage;
use App\Services\WhatsApp\Llm\GeminiProvider;
use App\Services\WhatsApp\Llm\LlmException;
use App\Services\WhatsApp\Llm\LlmFactory;
use App\Services\WhatsApp\LlmCircuitBreaker;
use App\Services\WhatsApp\Media\MediaArchiver;
use App\Services\WhatsApp\MessageTranslator;
use App\Services\WhatsApp\Outbound\VoiceNote;
use App\Services\WhatsApp\WaLog;
use App\Support\FeatureFlags;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * تفريغ رسالة الموظف الصوتية نصاً للزملاء (E34.6، العلم staff_voice_transcript): بعد إرسالها، على طابور wa-media (E14)
 * فلا يؤخر ردود البوت. المسار نفسه لتفريغ الوارد: GeminiProvider::transcribe بعد قاطع المزوّد (E12) ونتيجته فيه، وتأكيد
 * المدير أن المفتاح على الطبقة المدفوعة (E29). Claude لا يقبل الصوت، فمع anthropic لا نداء.
 *
 * النتيجة في payload._transcript (3000 حرف) وفي body «🎤 (صوت الموظف) …»: يقرؤها الزملاء والبحث والتدقيق، ويقرؤها
 * البوت في السياق كرسالة موظف (assistant) إن عاد بعد الإيقاف المؤقت. لا يُرسل التفريغ للعميل أبداً. وأي تعذّر يكتب
 * _transcript_failed بسببه ويبقى body كما هو والملف قابلاً للاستماع.
 */
final class TranscribeStaffVoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MAX_CHARS = 3000;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(public int $messageId)
    {
        $this->onQueue(config('whatsapp.media.queue', 'wa-media'));
    }

    public static function enabled(): bool
    {
        return FeatureFlags::on('staff_voice_transcript');
    }

    /** بعد إرسال الرسالة الصوتية: يُجدول إن كان العلم مفعّلاً (الحمولة رقم الصف وحده) */
    public static function schedule(int $messageId): void
    {
        if (self::enabled()) {
            self::dispatch($messageId)->afterCommit();
        }
    }

    public function handle(): void
    {
        if (!self::enabled()) {
            return;
        }
        $row = WaMessage::find($this->messageId);
        if (!$row || $row->direction !== 'out' || $row->source !== 'staff' || $row->msg_type !== VoiceNote::TYPE || !$row->media_path) {
            return;
        }
        $payload = (array) ($row->payload ?? []);
        // متساوية الأثر: ما فُرّغ أو حُسم فشله لا يُعاد
        if (isset($payload['_transcript']) || isset($payload['_transcript_failed'])) {
            return;
        }

        $s = WaBotSetting::current();
        $provider = (string) ($s->provider ?: 'google');
        if ($provider !== 'google') {
            self::finish($row, failed: 'provider');

            return;
        }
        if (blank($s->llmKey())) {
            self::finish($row, failed: 'no_key');

            return;
        }
        if (!MessageTranslator::termsConfirmed($s)) {
            self::finish($row, failed: 'terms');

            return;
        }
        if (LlmCircuitBreaker::allow($provider) === LlmCircuitBreaker::BLOCK) {
            // المزوّد معطّل: محاولة ثانية بعد دقيقتين، ثم يبقى الصوت بلا تفريغ
            if ($this->attempts() < $this->tries) {
                $this->release(120);

                return;
            }
            self::finish($row, failed: 'llm_down');

            return;
        }

        $bytes = rescue(fn () => MediaArchiver::disk()->get((string) $row->media_path), null, false);
        if (!is_string($bytes) || $bytes === '') {
            self::finish($row, failed: 'file');

            return;
        }

        $llm = LlmFactory::make($provider);
        if (!$llm instanceof GeminiProvider) {
            self::finish($row, failed: 'provider');

            return;
        }
        $model = (string) $s->model;
        $started = microtime(true);
        try {
            $r = $llm->transcribe((string) $s->llmKey(), $model, $bytes, VoiceNote::MIME, max(60, (int) config('whatsapp.voice.transcribe_timeout', 60)));
        } catch (LlmException $e) {
            LlmCircuitBreaker::recordFailure($provider, $e, $model);
            WaLog::warning('voice_out.transcription_failed', ['message_id' => $row->id, 'conv_id' => $row->conversation_id, 'reason' => $e->kind(), 'http' => $e->httpStatus]);
            if ($e->retryable() && $this->attempts() < $this->tries) {
                $this->release(30);

                return;
            }
            self::finish($row, failed: 'llm');

            return;
        } catch (\Throwable $e) {
            WaLog::error('voice_out.transcription_failed', ['message_id' => $row->id, 'conv_id' => $row->conversation_id, 'exception' => $e]);
            self::finish($row, failed: 'error');

            return;
        }
        LlmCircuitBreaker::recordSuccess($provider);
        $ms = (int) round((microtime(true) - $started) * 1000);

        // استهلاك منفصل (voice_out): خارج إحصاءات الرد الحي في BotAnalytics
        rescue(fn () => WaBotUsage::create([
            'conversation_id' => $row->conversation_id, 'source' => 'voice_out', 'model' => $model, 'calls' => 1,
            'prompt_tokens' => (int) ($r['usage']['in'] ?? 0), 'output_tokens' => (int) ($r['usage']['out'] ?? 0),
            'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'tools_used' => [], 'leaked' => false,
            'latency_ms' => $ms, 'created_at' => now(),
        ]), null, false);

        $text = self::clean((string) ($r['text'] ?? ''));
        if ($text === '' || $text === '[غير مفهومة]') {
            self::finish($row, failed: 'empty');

            return;
        }
        self::finish($row, text: $text);
        WaLog::info('voice_out.transcribed', ['message_id' => $row->id, 'conv_id' => $row->conversation_id, 'model' => $model, 'ms' => $ms]);
    }

    /** المحاولات نفدت باستثناء غير متوقع: يبقى الصوت بلا تفريغ بسبب ظاهر */
    public function failed(?\Throwable $e): void
    {
        if ($row = WaMessage::find($this->messageId)) {
            self::finish($row, failed: 'error');
        }
    }

    /**
     * يكتب النتيجة على الصف من القاعدة الآن (حالات التسليم تتقدم أثناء التفريغ)، عبر Eloquent فيُحدَّث نص البحث (E30).
     * لا يكتب فوق تفريغ سابق.
     */
    private static function finish(WaMessage $row, ?string $text = null, ?string $failed = null): void
    {
        $fresh = WaMessage::find($row->id);
        if (!$fresh) {
            return;
        }
        $payload = (array) ($fresh->payload ?? []);
        if (isset($payload['_transcript'])) {
            return;
        }
        if ($text !== null) {
            $payload['_transcript'] = mb_substr($text, 0, self::MAX_CHARS);
            unset($payload['_transcript_failed']);
            $fresh->body = VoiceNote::transcribedBody($payload['_transcript']);
        } else {
            $payload['_transcript_failed'] = $failed ?? 'error';
        }
        $fresh->payload = $payload;
        $fresh->save();
    }

    /** بلا محارف تحكم أو اتجاه، ومسافات موحّدة */
    private static function clean(string $text): string
    {
        $text = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        return trim($text);
    }
}
