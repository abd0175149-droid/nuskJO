<?php

namespace App\Support;

use InvalidArgumentException;

/** مقاطع مسار الروابط الصادرة (Graph وGemini): قيمة من الإعداد أو القاعدة لا تغيّر المسار ولا المضيف. */
final class UrlPath
{
    /**
     * @throws InvalidArgumentException UNSAFE_PATH_SEGMENT
     */
    public static function segment(string $value): string
    {
        if ($value === '' || $value === '.' || $value === '..' || strlen($value) > 128
            || preg_match('/[\/\\\\\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('UNSAFE_PATH_SEGMENT');
        }

        return rawurlencode($value);
    }
}
