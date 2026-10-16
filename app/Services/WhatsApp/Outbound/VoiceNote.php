<?php

namespace App\Services\WhatsApp\Outbound;

use App\Models\WaMessage;

/**
 * رسالة الموظف الصوتية على المغلف الموحّد (E34): النوع audio مسجّل بـ registerType وسيطاً (media_* والحجز المؤجل)،
 * بالحمولة الرسمية {type: audio, audio: {id, voice: true}}: ملف ogg/opus أحادي مرفوع بالمعرّف، فيراه العميل رسالة صوتية
 * بأيقونة الميكروفون لا ملفاً مرفقاً. المصدر staff وحده (dispatch يرفض voice لغيره، وNoBotAudioTest يحرس البوت).
 */
final class VoiceNote
{
    public const TYPE = 'audio';

    public const MIME = 'audio/ogg';

    /** اسم الملف في الرفع المتعدد الأجزاء وفي التنزيل الداخلي (لا اسم من المتصفح) */
    public const FILENAME = 'voice-note.ogg';

    /** يسجّل النوع على المغلف (متساوي الأثر): يُستدعى قبل بناء أي رسالة صوتية */
    public static function register(): void
    {
        OutboundMessage::registerType(self::TYPE,
            fn (OutboundMessage $m) => ['id' => (string) $m->mediaId] + (!empty($m->data['voice']) ? ['voice' => true] : []),
            fn (OutboundMessage $m) => self::display((float) ($m->data['seconds'] ?? 0)),
            media: true);
    }

    /** رسالة صوتية بالمعرّف، أو بلا معرّف لحجز مؤجل (المعرّف من الوظيفة بعد الرفع) */
    public static function message(?string $mediaId, float $seconds): OutboundMessage
    {
        self::register();
        $m = OutboundMessage::custom(self::TYPE, ['voice' => true, 'seconds' => (int) round(max(0.0, $seconds))]);

        return $mediaId !== null && trim($mediaId) !== '' ? $m->withMediaId($mediaId) : $m;
    }

    /** نص الصف في الصندوق حتى يصل التفريغ: «🎤 رسالة صوتية من الموظف (0:42)» */
    public static function display(float $seconds): string
    {
        return '🎤 رسالة صوتية من الموظف ('.self::clock($seconds).')';
    }

    /** النص بعد التفريغ للزملاء وسياق البوت (لا يصل العميل) */
    public static function transcribedBody(string $text): string
    {
        return '🎤 (صوت الموظف) '.$text;
    }

    public static function clock(float $seconds): string
    {
        $s = (int) round(max(0.0, $seconds));

        return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }

    /**
     * حقل voice في الخيط لرسالة الموظف الصوتية: المدة، والتفريغ للزملاء أو سبب تعذّره. لمن يرى المحادثة (whatsapp.view
     * داخل نطاقه)؛ الملف نفسه من مسار E14 لمن يملك whatsapp.media. لا حمولة خام ولا مسار.
     *
     * @return array{voice?: array{seconds: int, transcript: ?string, transcript_failed: ?string}}
     */
    public static function threadFields(WaMessage $m): array
    {
        $p = (array) ($m->payload ?? []);
        if ($m->direction !== 'out' || $m->source !== 'staff' || $m->msg_type !== self::TYPE || !isset($p['_voice'])) {
            return [];
        }

        return ['voice' => [
            'seconds' => (int) round((float) data_get($p, '_voice.seconds', 0)),
            'transcript' => is_string($p['_transcript'] ?? null) ? $p['_transcript'] : null,
            'transcript_failed' => is_string($p['_transcript_failed'] ?? null) ? $p['_transcript_failed'] : null,
        ]];
    }

    /** حمولة رسالة صوتية أصلية (audio بعلم voice) */
    public static function isVoicePayload(array $payload): bool
    {
        return ($payload['type'] ?? null) === self::TYPE && !empty($payload[self::TYPE]['voice']);
    }
}
