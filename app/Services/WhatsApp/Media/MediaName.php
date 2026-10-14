<?php

namespace App\Services\WhatsApp\Media;

use App\Models\WaMessage;

/**
 * اسم الملف عند التنزيل: من اسم العميل بعد التنظيف، والامتداد من النوع المكتشف دائماً (لا من الاسم).
 * يُحذف ما يقلب الاتجاه (‮gpj.exe يصير gpj.jpg) ومحارف التحكم والفواصل و«..».
 */
final class MediaName
{
    public static function extension(WaMessage $m): string
    {
        return MediaArchiver::allowedTypes()[(string) $m->media_mime][0] ?? 'bin';
    }

    public static function safe(WaMessage $m): string
    {
        $ext = self::extension($m);
        $name = (string) $m->media_filename;
        $name = preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', '', $name) ?? '';
        $name = str_replace(['/', '\\', '..'], ' ', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '', " .\t");
        $name = preg_replace('/\.[^.]{1,10}$/u', '', $name) ?? $name;
        $name = trim(mb_substr($name, 0, 120), ' .');

        return ($name !== '' ? $name : "whatsapp-{$m->id}").'.'.$ext;
    }

    public static function ascii(WaMessage $m): string
    {
        return "document-{$m->id}.".self::extension($m);
    }
}
