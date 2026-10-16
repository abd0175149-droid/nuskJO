<?php

namespace App\Exceptions;

/** تعذّر التحويل: ملف تالف أو صيغة لا يفهمها ffmpeg، أو انتهت المهلة، أو ffmpeg غير موجود في الصورة */
class VoiceConversionException extends VoiceNoteException
{
    public static function failed(string $reason = 'VOICE_CONVERT_FAILED'): self
    {
        return new self($reason, 'تعذّر تجهيز الرسالة الصوتية — سجّلها من جديد.', 422);
    }

    public static function unavailable(): self
    {
        return new self('VOICE_UNAVAILABLE', 'الرسائل الصوتية غير متاحة على الخادم الآن — أبلغ المدير.', 503);
    }
}
