<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Llm\GeminiProvider;
use App\Services\WhatsApp\Llm\LlmFactory;
use Illuminate\Support\Facades\Log;

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

    /** يفرّغ آخر الصوتيات غير المفرّغة في المحادثة */
    public static function run(WaConversation $conv): void
    {
        $s = WaBotSetting::current();
        if (!$s->enabled || !$s->llmKey()) {
            return;
        }

        $pending = $conv->messages()
            ->where('direction', 'in')
            ->where('msg_type', 'audio')
            ->orderByDesc('id')->limit(3)->get()
            ->filter(fn (WaMessage $m) => !data_get($m->payload, '_transcribed'));

        foreach ($pending as $m) {
            self::one($m, $s);
        }
    }

    private static function one(WaMessage $m, WaBotSetting $s): void
    {
        $payload = $m->payload ?? [];
        $text = null;
        $t0 = microtime(true);

        try {
            $provider = LlmFactory::make($s->provider);
            $mediaId = (string) data_get($payload, 'audio.id', '');

            // Claude لا يقبل الصوت — نُبقي العنصر النائب ويطلب البوت الكتابة
            if ($provider instanceof GeminiProvider && $mediaId !== '') {
                $media = WhatsAppChannel::downloadMedia($mediaId);
                if ($media) {
                    $mime = explode(';', $media['mime'])[0] ?: 'audio/ogg';
                    $r = $provider->transcribe((string) $s->llmKey(), (string) $s->model, $media['bytes'], $mime);
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
            Log::warning('WA voice transcription failed', ['message' => $m->id, 'error' => $e->getMessage()]);
        }

        $payload['_transcribed'] = true;
        $m->update([
            'body' => $text !== null ? '🎤 ' . mb_substr($text, 0, 3000) : self::FAILED_BODY,
            'payload' => $payload,
        ]);
    }
}
