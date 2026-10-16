<?php

namespace App\Services\WhatsApp;

/**
 * شرط ميتا للرسالة الصوتية قبل الرفع (E34.4): ogg بترميز OPUS وقناة واحدة، و512KB أو أقل كي يظهر زر التشغيل عند العميل
 * (وإلا زر تنزيل). يُفحص ناتج المحوّل نفسه، فلا يصل ميتا ملف بغير الصيغة ولو أخطأ المحوّل.
 *
 * والإدخال الخام من المتصفح قبل التحويل بقائمة ضيقة من finfo (rawMimeAllowed): webm وogg وmp4/m4a فقط.
 */
final class VoiceNoteValidator
{
    /** ما يسجّله Chrome وFirefox وSafari بحسب finfo (لا يُقبل غيرها عبر voice=1) */
    public const RAW_MIMES = ['audio/webm', 'video/webm', 'audio/ogg', 'application/ogg', 'audio/mp4', 'video/mp4', 'audio/x-m4a'];

    private const OGG_MIMES = ['audio/ogg', 'application/ogg'];

    /** سبب الرفض بالعربية، أو null إن كان الملف رسالة صوتية سليمة */
    public static function check(string $path): ?string
    {
        clearstatcache(true, $path);
        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size < 1) {
            return 'الملف الصوتي فارغ.';
        }
        if ($size > (int) config('whatsapp.voice.max_voice_bytes', 524288)) {
            return 'الرسالة الصوتية أكبر من 512KB فلن يظهر زر التشغيل عند العميل.';
        }

        $head = (string) @file_get_contents($path, false, null, 0, 512);
        if (!str_starts_with($head, 'OggS')) {
            return 'الملف ليس بصيغة Ogg.';
        }
        // صفحة Ogg الأولى: ترويسة 27 بايتاً ثم جدول المقاطع بعددها (البايت 26)، ثم أول حزمة: OpusHead وبعدها الإصدار ثم القنوات
        $segments = strlen($head) > 26 ? ord($head[26]) : 0;
        $packet = substr($head, 27 + $segments, 19);
        if (!str_starts_with($packet, 'OpusHead') || strlen($packet) < 10) {
            return 'الترميز ليس OPUS.';
        }
        if (ord($packet[9]) !== 1) {
            return 'الرسالة الصوتية يجب أن تكون بقناة واحدة (mono).';
        }

        $mime = self::mime($path);
        if (!in_array($mime, self::OGG_MIMES, true)) {
            return 'نوع الملف لا يطابق ogg.';
        }

        return null;
    }

    /** نوع الإدخال الخام مسموح للتحويل (finfo، لا ما أعلنه المتصفح) */
    public static function rawMimeAllowed(?string $mime): bool
    {
        return $mime !== null && in_array($mime, self::RAW_MIMES, true);
    }

    public static function mime(string $path): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = @$finfo->file($path);

        return is_string($mime) ? strtolower($mime) : null;
    }
}
