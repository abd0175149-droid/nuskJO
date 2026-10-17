<?php

namespace App\Mcp\Servers;

/**
 * سقف نتيجة الأداة (E40.9، 20KB): تُقص أطول قائمة في النتيجة عنصراً عنصراً حتى تتسع، ويُضاف truncated=true.
 * لا قص وسط نص ولا JSON مكسور.
 */
final class ResultLimiter
{
    public static function fit(array $data, int $maxBytes): array
    {
        $truncated = false;
        for ($i = 0; $i < 10000 && strlen((string) json_encode($data, JSON_UNESCAPED_UNICODE)) > $maxBytes; $i++) {
            $path = self::longestList($data);
            if ($path === null) {
                return ['truncated' => true, 'error' => 'النتيجة أكبر من الحد'];
            }
            $list = data_get($data, $path);
            array_pop($list);
            data_set($data, $path, $list);
            $truncated = true;
        }

        return $truncated ? $data + ['truncated' => true] : $data;
    }

    /** مسار أطول قائمة غير فارغة (بالبايت)، أو null */
    private static function longestList(array $data): ?string
    {
        return self::scan($data, '')[0];
    }

    /** @return array{0: ?string, 1: int} */
    private static function scan(array $data, string $prefix): array
    {
        [$best, $bestSize] = [null, 0];
        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (array_is_list($value) && $value !== []) {
                $size = strlen((string) json_encode($value, JSON_UNESCAPED_UNICODE));
                if ($size > $bestSize) {
                    [$best, $bestSize] = [$path, $size];
                }
            }
            [$inner, $innerSize] = self::scan($value, $path);
            if ($inner !== null && $innerSize > $bestSize) {
                [$best, $bestSize] = [$inner, $innerSize];
            }
        }

        return [$best, $bestSize];
    }
}
