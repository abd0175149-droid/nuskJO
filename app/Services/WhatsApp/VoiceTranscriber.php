<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Llm\GeminiProvider;
use App\Services\WhatsApp\Llm\LlmFactory;

/**
 * تفريغ الرسائل الصوتية الواردة إلى نصّ قبل أن يقرأها البوت.
 *
 * الصوتيات شائعة جداً بين العملاء، وكان البوت يرى «🎤 رسالة صوتية» فقط.
 * التفريغ يكتب النصّ في جسم الرسالة نفسها، فيقرؤه البوت ضمن السياق كأنّه
 * مكتوب، ويراه الموظف في صندوق المحادثات أيضاً.
 *
 * يُستدعى من وظيفة الردّ داخل قفل المحادثة، فلا تُفرَّغ رسالة مرّتين.
 */
class VoiceTranscriber
{
    public const FAILED_BODY = '🎤 رسالة صوتية (تعذّر تفريغها)';

    /**
     * يفرّغ آخر الصوتيات غير المفرّغة في المحادثة، داخل ميزانية الرد إن مُرّر تشغيلها:
     * بأقل من VOICE_MIN_SECONDS يُترك الصوت بلا تفريغ فيطلب البوت الكتابة (SYSTEM_RULES).
     */
    public static function run(WaConversation $conv, ?ReplyRun $run = null): void
    {
        $s = WaBotSetting::current();
        // أرقام الإدارة تُفرَّغ صوتياتها حتى لو كان بوت العملاء موقوفاً
        if (!$s->llmKey() || (!$s->enabled && !\App\Models\WaAdminNumber::forPhone($conv->phone))) {
            return;
        }

        $pending = $conv->messages()
            ->where('direction', 'in')
            ->where('msg_type', 'audio')
            ->orderByDesc('id')->limit(3)->get()
            ->filter(fn (WaMessage $m) => !data_get($m->payload, '_transcribed'));
        // قاطع المزوّد مفتوح (E12): لا نداء، وتبقى بلا _transcribed فتُفرَّغ بعد التعافي
        if ($pending->isNotEmpty() && LlmCircuitBreaker::allow((string) $s->provider) === LlmCircuitBreaker::BLOCK) {
            return;
        }

        foreach ($pending as $m) {
            if ($run && !$run->hasAtLeast(ReplyRun::VOICE_MIN_SECONDS)) {
                self::skipForBudget($m);

                continue;
            }
            self::one($m, $s, $run);
        }
    }

    /** لا وقت لتفريغه: يُعامل كتفريغ فاشل، فيطلب البوت من العميل الكتابة بدل أن يتجاوز الرد مهلته */
    private static function skipForBudget(WaMessage $m): void
    {
        WaLog::warning('voice.skipped_budget', ['message_id' => $m->id, 'conv_id' => $m->conversation_id]);
        $m->update([
            'body' => self::FAILED_BODY,
            'payload' => array_merge($m->payload ?? [], ['_transcribed' => true, '_media_error' => 'budget']),
        ]);
    }

    private static function one(WaMessage $m, WaBotSetting $s, ?ReplyRun $run = null): void
    {
        $payload = $m->payload ?? [];
        $text = null;
        $t0 = microtime(true);

        try {
            $provider = LlmFactory::make($s->provider);
            $mediaId = (string) data_get($payload, 'audio.id', '');

            // Claude لا يقبل الصوت — نُبقي العنصر النائب ويطلب البوت الكتابة
            if (!$provider instanceof GeminiProvider && $mediaId !== '') {
                $payload['_media_error'] = WhatsAppChannel::ERR_UNSUPPORTED;
            }
            if ($provider instanceof GeminiProvider && $mediaId !== '') {
                $fetched = WhatsAppChannel::fetchMedia($mediaId);
                // مضيف شبكة ميتا (لا بيانات شخصية): يُراجع خلال أسبوع وضع التسجيل قبل الرفض
                if ($fetched['ok']) {
                    $payload['_media_host'] = $fetched['host'];
                } else {
                    $payload['_media_error'] = $fetched['code'];
                }
                $media = $fetched['ok'] ? $fetched : null;
                if ($media) {
                    $mime = explode(';', $media['mime'])[0] ?: 'audio/ogg';
                    $r = $provider->transcribe((string) $s->llmKey(), (string) $s->model, $media['bytes'], $mime, $run?->voiceTimeout());
                    LlmCircuitBreaker::recordSuccess($provider->key());
                    $text = $r['text'] !== '' && $r['text'] !== '[غير مفهومة]' ? $r['text'] : null;

                    WaBotUsage::create([
                        'conversation_id' => $m->conversation_id,
                        'source' => 'voice',
                        'model' => (string) $s->model,
                        'calls' => 1,
                        'prompt_tokens' => $r['usage']['in'],
                        'output_tokens' => $r['usage']['out'],
                        'cache_read_tokens' => 0,
                        'cache_write_tokens' => 0,
                        'tools_used' => [],
                        'leaked' => false,
                        'latency_ms' => (int) ((microtime(true) - $t0) * 1000),
                        'created_at' => now(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // error لا warning: الإنتاج يسجّل من error فما فوق، فكان الفشل يختفي
            WaLog::error('voice.transcription_failed', ['message_id' => $m->id, 'conv_id' => $m->conversation_id, 'exception' => $e]);
            // خطأ المزوّد يُعدّ في القاطع قبل ابتلاعه (E12)
            if ($e instanceof \App\Services\WhatsApp\Llm\LlmException) {
                LlmCircuitBreaker::recordFailure((string) $s->provider, $e, (string) $s->model);
            }
        }

        $payload['_transcribed'] = true;
        $m->update([
            'body' => $text !== null ? '🎤 ' . mb_substr($text, 0, 3000) : self::FAILED_BODY,
            'payload' => $payload,
        ]);
    }
}
