<?php

namespace App\Services\WhatsApp;

/**
 * معاينة الرابط (E33، C002): text.preview_url=true يجعل خوادم واتساب تجلب أول رابط في النص. لرد الموظف وحده،
 * وبخانة صريحة، وللروابط العامة وحدها: لا مضيفنا ولا خوادمنا المشتركة ولا عنوان IP ولا رابط موقّع أو مؤقت ولا مسار
 * ملفات أو API، فلا يجلب أحد رابطاً داخلياً ولا يستهلك رابطاً موقّعاً. البوت والنظام لا يفعّلانها أبداً (يفرضه dispatch).
 */
final class LinkPreviewPolicy
{
    private const SIGNED_KEYS = ['signature', 'sig', 'token', 'expires', 'key'];

    private const BLOCKED_PATHS = ['/storage/', '/api/', '/s/'];

    public const MAX_LENGTH = 2000;

    /** @return array{allowed: bool, reason: ?string} */
    public static function check(string $text): array
    {
        if (!preg_match('~https?://[^\s<>"\'«»]+~iu', $text, $m)) {
            return self::no('لا يوجد رابط في الرسالة.');
        }
        $url = rtrim($m[0], '.,،؛:!?)]}');
        if (mb_strlen($url) > self::MAX_LENGTH) {
            return self::no('الرابط أطول من المسموح.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return self::no('المعاينة لروابط https وحدها.');
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return self::no('رابط غير صالح للمعاينة.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return self::no('لا معاينة لعنوان داخلي.');
        }
        if (self::blockedHost($host)) {
            return self::no('لا معاينة لروابط نظامنا أو خوادمنا.');
        }
        $path = '/'.ltrim(strtolower((string) ($parts['path'] ?? '/')), '/');
        foreach (self::BLOCKED_PATHS as $blocked) {
            if (str_starts_with($path, $blocked) || ($path.'/') === $blocked) {
                return self::no('لا معاينة لروابط الملفات أو الواجهات البرمجية.');
            }
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (array_keys($query) as $key) {
            $k = strtolower((string) $key);
            if (in_array($k, self::SIGNED_KEYS, true) || str_starts_with($k, 'x-amz-')) {
                return self::no('لا معاينة لرابط موقّع أو مؤقت.');
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    public static function allows(string $text): bool
    {
        return self::check($text)['allowed'];
    }

    /** هل في النص رابط http أو https أصلاً (تظهر خانة المعاينة في المنشئ) */
    public static function hasLink(string $text): bool
    {
        return preg_match('~https?://\S~iu', $text) === 1;
    }

    private static function blockedHost(string $host): bool
    {
        $own = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $blocked = array_filter([$own, ...array_map(fn ($h) => strtolower(ltrim(trim((string) $h), '.')), (array) config('whatsapp.preview_block_hosts', []))]);
        foreach ($blocked as $b) {
            if ($host === $b || str_ends_with($host, '.'.$b)) {
                return true;
            }
        }

        return false;
    }

    private static function no(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }
}
