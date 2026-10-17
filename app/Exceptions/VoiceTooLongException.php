<?php

namespace App\Exceptions;

/** أطول من الحد بالمدة، أو أكبر من 512KB بعد إعادة الترميز بالمعدل الأدنى (فلا يظهر زر التشغيل عند العميل) */
class VoiceTooLongException extends VoiceNoteException
{
    public function __construct(string $reason = 'VOICE_TOO_LONG')
    {
        $max = (int) config('whatsapp.voice.max_seconds', 120);

        parent::__construct($reason, sprintf('الرسالة أطول من المسموح (حتى %d:%02d) — سجّل رسالة أقصر.', intdiv($max, 60), $max % 60), 422);
    }
}
