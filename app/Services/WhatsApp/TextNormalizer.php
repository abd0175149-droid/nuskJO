<?php

namespace App\Services\WhatsApp;

/**
 * تطبيع نصوص الواتساب (E11): للبحث (بلا تشكيل، والهمزات والتاء المربوطة والألف المقصورة موحّدة، والأرقام
 * الهندية أرقاماً)، وللأسماء التي يضعها العميل بنفسه (غير موثوقة: بلا محارف تحكم ولا محارف اتجاه).
 * كل العمليات بـ mb_* وpreg /u: trim() بقائمة محارف يقطع بايتات الحروف العربية (مثل «ن»).
 */
final class TextNormalizer
{
    private const LETTERS = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي'];

    private const DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

    public static function forSearch(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        $t = mb_strtolower($text);
        // التشكيل والألف الخنجرية والتطويل
        $t = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $t) ?? $t;
        $t = strtr($t, self::LETTERS + self::DIGITS);

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /** اسم يضعه العميل بنفسه: للعرض فقط، بلا محارف تحكم (C0/C1) ولا اتجاه (U+202E يقلب العرض)، ومقصوص */
    public static function untrustedName(mixed $value, int $max = 120): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $clean = preg_replace('/[\p{Cc}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', $value) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }
}
