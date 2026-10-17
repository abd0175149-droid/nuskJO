<?php

namespace App\Services\WhatsApp\Settings;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/**
 * مفاتيح تشفير التطبيق لأسرار القناة (E10): هل يُفك سر بالمفتاح الحالي وحده (لا بـ APP_PREVIOUS_KEYS)؟
 * بهذا يعرف whatsapp:doctor أن تدوير APP_KEY لم يكتمل، ويتحقق whatsapp:reencrypt-secrets من نتيجته.
 * لا يُعاد أي مفتاح: بصمة sha256 قصيرة فقط.
 */
final class AppKeys
{
    public static function current(): Encrypter
    {
        return new Encrypter(self::parse((string) config('app.key')), (string) config('app.cipher'));
    }

    /** null: لا قيمة؛ true: بالحالي وحده؛ false: لا يُفك به (سابق أو تالف) */
    public static function decryptsWithCurrent(?string $raw): ?bool
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            self::current()->decryptString($raw);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    public static function fingerprint(string $key): string
    {
        return substr(hash('sha256', $key), 0, 8);
    }

    /** @return list<string> */
    public static function previous(): array
    {
        return array_values(array_filter((array) config('app.previous_keys', [])));
    }

    private static function parse(string $key): string
    {
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }
}
