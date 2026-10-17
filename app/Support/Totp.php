<?php

namespace App\Support;

/**
 * رموز TOTP بحسب RFC 6238 (HMAC-SHA1، كل 30 ثانية، 6 أرقام) وترميز base32 بحسب RFC 4648، بلا حزمة خارجية (E27).
 * هذا ما تقرؤه تطبيقات المصادقة كلها. السر لا يُكتب في أي سجل، والمقارنة بزمن ثابت.
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** الأرقام العربية والفارسية من لوحة مفاتيح الهاتف تُقبل كما هي */
    private const DIGIT_MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /** سر جديد: 20 بايتاً (160 بت كما توصي RFC 4226) = 32 حرفاً base32 */
    public static function newSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function timestep(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /** رمز خطوة زمنية بعينها */
    public static function at(#[\SensitiveParameter] string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * الخطوة التي يطابقها الرمز ضمن ±$window من الآن، بشرط أن تكون أحدث من $after (آخر خطوة قُبلت)،
     * فلا يُقبل الرمز نفسه مرتين ولو داخل نافذته. null إن لم يطابق.
     */
    public static function match(#[\SensitiveParameter] string $secret, string $code, ?int $after = null, int $window = 1, ?int $time = null): ?int
    {
        $code = self::normalize($code);
        if ($code === null) {
            return null;
        }

        $now = self::timestep($time);
        $found = null;
        // كل الخطوات تُحسب (لا خروج مبكر): زمن الرد لا يكشف أي خطوة طابقت
        for ($step = $now - $window; $step <= $now + $window; $step++) {
            $ok = hash_equals(self::at($secret, $step), $code);
            if ($ok && ($after === null || $step > $after) && $found === null) {
                $found = $step;
            }
        }

        return $found;
    }

    /** ستة أرقام بعد حذف المسافات والشرطات، أو null */
    public static function normalize(string $code): ?string
    {
        $code = preg_replace('/[\s\-\x{200E}\x{200F}]+/u', '', strtr($code, self::DIGIT_MAP)) ?? '';

        return preg_match('/^\d{'.self::DIGITS.'}$/', $code) === 1 ? $code : null;
    }

    /** رابط otpauth الذي يفهمه تطبيق المصادقة (يُرسم QR على خادمنا، ولا يمر بأي خدمة خارجية) */
    public static function uri(#[\SensitiveParameter] string $secret, string $issuer, string $account): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** للإدخال اليدوي: مجموعات من أربعة أحرف */
    public static function grouped(#[\SensitiveParameter] string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    /** @throws \InvalidArgumentException حرف خارج أبجدية base32 */
    public static function base32Decode(#[\SensitiveParameter] string $secret): string
    {
        $secret = strtoupper(preg_replace('/[\s=]+/', '', $secret) ?? '');
        if ($secret === '' || strspn($secret, self::ALPHABET) !== strlen($secret)) {
            throw new \InvalidArgumentException('base32 غير صالح');
        }
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
