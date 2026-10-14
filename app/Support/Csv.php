<?php

namespace App\Support;

/**
 * CSV يفتحه Excel دون أن ينفّذ ما فيه: خلية نصية تبدأ بـ = أو + أو - أو @ (أو محرف تحكم) تُسبق بفاصلة عليا،
 * فاسم عميل وصل من واتساب لا يصير صيغة. الأرقام والنصوص الرقمية (-12.50) تبقى أرقاماً.
 * نظيره في الواجهة resources/js/utils/csv.js بالقاعدة نفسها.
 */
final class Csv
{
    private const DANGEROUS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    public static function cell(mixed $v): int|float|string
    {
        if ($v === null) {
            return '';
        }
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        $s = (string) $v;
        if (preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            return $s;
        }
        $first = substr(ltrim($s, ' '), 0, 1);

        return in_array($first, self::DANGEROUS, true) ? "'".$s : $s;
    }

    /** @param  resource  $h */
    public static function put($h, array $row): void
    {
        fputcsv($h, array_map(self::cell(...), $row), ',', '"', '');
    }

    /** علامة UTF-8 أول الملف: Excel يقرأ العربية بها */
    public static function bom($h): void
    {
        fwrite($h, "\xEF\xBB\xBF");
    }
}
