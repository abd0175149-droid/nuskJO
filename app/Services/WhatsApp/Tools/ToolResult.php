<?php

namespace App\Services\WhatsApp\Tools;

/**
 * شكل نتيجة الأداة قبل أن تصل للنموذج (E15.7): الفارغة ok، وما تجاوز سقفه تُقصّ أطول قائمة فيه بقصّ معلن
 * (truncated)، ثم النصوص الطويلة إلى 300 حرف إن بقيت كبيرة.
 */
final class ToolResult
{
    public const TRUNCATED_NOTE = 'النتيجة مقطوعة؛ اطلب من العميل تحديد ما يريد أو استعمل أداة التفاصيل.';

    private const LONG_TEXT = 300;

    public static function shape(array $out, int $maxBytes): array
    {
        if ($out === []) {
            return ['ok' => true];
        }
        if (self::bytes($out) <= $maxBytes) {
            return $out;
        }

        // أطول قائمة (مصفوفة بفهرس رقمي) في المستوى الأول أو الثاني تُقصّ حتى تناسب
        $path = self::longestList($out);
        if ($path !== null) {
            $list = data_get($out, $path);
            while (count($list) > 1 && self::bytes(self::withList($out, $path, $list)) > $maxBytes) {
                array_pop($list);
            }
            $out = self::withList($out, $path, $list);
            $out['truncated'] = true;
            $out['truncation_note'] = self::TRUNCATED_NOTE;
        }
        if (self::bytes($out) > $maxBytes) {
            array_walk_recursive($out, function (&$v) {
                if (is_string($v) && mb_strlen($v) > self::LONG_TEXT) {
                    $v = mb_substr($v, 0, self::LONG_TEXT).'…';
                }
            });
            $out['truncated'] = true;
            $out['truncation_note'] ??= self::TRUNCATED_NOTE;
        }

        return $out;
    }

    public static function bytes(array $out): int
    {
        return strlen((string) json_encode($out, JSON_UNESCAPED_UNICODE));
    }

    private static function longestList(array $out): ?string
    {
        $best = null;
        $bestBytes = 0;
        foreach ($out as $k => $v) {
            if (!is_array($v)) {
                continue;
            }
            $candidates = array_is_list($v) ? [(string) $k => $v] : [];
            foreach ($v as $k2 => $v2) {
                if (is_array($v2) && array_is_list($v2) && $v2 !== []) {
                    $candidates["{$k}.{$k2}"] = $v2;
                }
            }
            foreach ($candidates as $path => $list) {
                if ($list !== [] && ($b = self::bytes($list)) > $bestBytes) {
                    [$best, $bestBytes] = [$path, $b];
                }
            }
        }

        return $best;
    }

    private static function withList(array $out, string $path, array $list): array
    {
        data_set($out, $path, $list);

        return $out;
    }
}
