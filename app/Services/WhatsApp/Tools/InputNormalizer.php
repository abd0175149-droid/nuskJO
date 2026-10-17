<?php

namespace App\Services\WhatsApp\Tools;

use Illuminate\Support\Carbon;

/**
 * تطبيع مدخلات أداة قبل التحقق (E15.3): ما ليس في المخطط يسقط (client_id وphone وconversation_id…)، والنصوص بلا
 * NUL ولا محارف تحكم ولا محارف اتجاه، والتواريخ Y-m-d أو null، والأعداد النصية أعداد، والنص المفرد مصفوفة حيث
 * المخطط مصفوفة، والنوع المجهول في enum فيه other يصير other.
 */
final class InputNormalizer
{
    private const CONTROL = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /** @param  array<string, array>  $properties  @param  array<string, string>  $rules */
    public static function normalize(array $in, array $properties, array $rules = []): array
    {
        $out = [];
        foreach ($properties as $key => $schema) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $out[$key] = self::value($in[$key], $schema, (string) ($rules[$key] ?? ''));
        }

        return $out;
    }

    private static function value(mixed $v, array $schema, string $rule): mixed
    {
        $type = $schema['type'] ?? 'string';

        if ($type === 'array') {
            $list = is_array($v) ? array_values($v) : ($v === null || $v === '' ? [] : [$v]);

            return array_map(fn ($x) => is_string($x) || is_numeric($x) ? self::text((string) $x) : $x, $list);
        }
        if ($type === 'integer') {
            if (is_int($v)) {
                return $v;
            }
            if (is_float($v) && floor($v) === $v && abs($v) < PHP_INT_MAX) {
                return (int) $v;
            }

            return is_string($v) && preg_match('/^-?\d{1,9}$/', trim(self::text($v))) ? (int) trim($v) : (is_numeric($v) ? $v : null);
        }
        if ($type === 'boolean') {
            return is_bool($v) ? $v : filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        }
        if (!is_string($v) && !is_numeric($v)) {
            return $v === null ? null : $v;
        }

        $text = self::text((string) $v);
        if (str_contains($rule, 'date')) {
            return self::date($text);
        }
        if (is_array($schema['enum'] ?? null) && !in_array($text, $schema['enum'], true) && in_array('other', $schema['enum'], true)) {
            return 'other';
        }

        return $text;
    }

    public static function text(string $v): string
    {
        return trim(preg_replace(self::CONTROL, '', $v) ?? '');
    }

    private static function date(string $v): ?string
    {
        if ($v === '') {
            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
