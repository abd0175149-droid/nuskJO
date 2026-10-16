<?php

namespace App\Services\WhatsApp\Search;

use App\Services\WhatsApp\TextNormalizer;

/**
 * مقتطف نتيجة البحث بلا HTML (E30.3): أجزاء {t, hl} يرسمها Vue نصاً مهرَّباً ويُبرز ما hl فيه. المطابقة على النص
 * المطبَّع وتُنقل بخريطة المواضع إلى النص الأصلي، فيُبرز «العُمرة» بتشكيلها لا «العمره». نافذة نحو 60 محرفاً قبل
 * أول تطابق و60 بعده. إن تعذّر الربط: بداية النص بلا إبراز. محارف التحكم والاتجاه تُحذف من العرض.
 */
final class Snippet
{
    public const BEFORE = 60;

    public const AFTER = 60;

    public const PLAIN = 140;

    private const MAX_RANGES = 20;

    /** ما يتبع المحرف قبله (تشكيل وتطويل): يدخل في الإبراز معه */
    private const TRAILING = '/^[\x{064B}-\x{065F}\x{0670}\x{0640}]$/u';

    private const DROP = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * @param  list<string>  $terms  مطبّعة كما أعادها SearchQuery::highlight
     * @return list<array{t: string, hl: bool}>
     */
    public static function segments(string $original, array $terms): array
    {
        $chars = preg_split('//u', $original, -1, PREG_SPLIT_NO_EMPTY);
        if (!$chars) {
            return [];
        }
        $mapped = $terms ? TextNormalizer::forSearchWithMap($original) : null;
        $ranges = $mapped ? self::ranges($mapped, $terms, $chars) : [];
        if ($ranges === []) {
            return self::plain($chars);
        }

        $n = count($chars);
        [$first, $firstEnd] = $ranges[0];
        $from = max(0, $first - self::BEFORE);
        $to = min($n, $firstEnd + self::AFTER);
        // حدود الكلمات: لا يبدأ المقتطف ولا ينتهي في منتصف كلمة إن وُجدت مسافة قريبة
        if ($from > 0) {
            for ($i = $from; $i < $first; $i++) {
                if (preg_match('/^\s$/u', $chars[$i])) {
                    $from = $i + 1;
                    break;
                }
            }
        }
        if ($to < $n) {
            for ($i = $to - 1; $i >= $firstEnd; $i--) {
                if (preg_match('/^\s$/u', $chars[$i])) {
                    $to = $i;
                    break;
                }
            }
        }

        $out = [];
        $cursor = $from;
        foreach ($ranges as [$s, $e]) {
            if ($s >= $to) {
                break;
            }
            $s = max($s, $from);
            $e = min($e, $to);
            if ($s > $cursor) {
                $out[] = [implode('', array_slice($chars, $cursor, $s - $cursor)), false];
            }
            if ($e > $s) {
                $out[] = [implode('', array_slice($chars, $s, $e - $s)), true];
            }
            $cursor = max($cursor, $e);
        }
        if ($cursor < $to) {
            $out[] = [implode('', array_slice($chars, $cursor, $to - $cursor)), false];
        }
        if ($from > 0) {
            array_unshift($out, ['…', false]);
        }
        if ($to < $n) {
            $out[] = ['…', false];
        }

        return self::clean($out);
    }

    /** @return list<array{0: int, 1: int}> مواضع المطابقة في النص الأصلي [من، إلى)، مرتبة ومدموجة */
    private static function ranges(array $mapped, array $terms, array $chars): array
    {
        ['text' => $norm, 'map' => $map] = $mapped;
        $found = [];
        foreach ($terms as $term) {
            $len = mb_strlen($term);
            if ($len === 0) {
                continue;
            }
            for ($at = mb_strpos($norm, $term); $at !== false && count($found) < self::MAX_RANGES * 4; $at = mb_strpos($norm, $term, $at + $len)) {
                $found[] = [$at, $at + $len];
            }
        }
        if ($found === []) {
            return [];
        }
        sort($found);

        $merged = [];
        foreach ($found as [$s, $e]) {
            $start = $map[$s];
            $end = $map[$e - 1] + 1;
            while (isset($chars[$end]) && preg_match(self::TRAILING, $chars[$end])) {
                $end++;
            }
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return array_slice($merged, 0, self::MAX_RANGES);
    }

    /** @return list<array{t: string, hl: bool}> */
    private static function plain(array $chars): array
    {
        $more = count($chars) > self::PLAIN;

        return self::clean([[implode('', array_slice($chars, 0, self::PLAIN)).($more ? '…' : ''), false]]);
    }

    /** @return list<array{t: string, hl: bool}> أسطر المقتطف سطر واحد، بلا محارف تحكم، والمتجاور من النوع نفسه مدموج */
    private static function clean(array $parts): array
    {
        $out = [];
        foreach ($parts as [$text, $hl]) {
            $text = preg_replace('/\s+/u', ' ', preg_replace(self::DROP, '', $text) ?? '') ?? '';
            if ($text === '') {
                continue;
            }
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['hl'] === $hl) {
                $out[$last]['t'] .= $text;
            } else {
                $out[] = ['t' => $text, 'hl' => $hl];
            }
        }

        return $out;
    }
}
