<?php

namespace App\Support;

use App\Models\WaBotSetting;
use Throwable;

/**
 * يحذف الأسرار من نص قبل أن يُكتب في سجل أو تدقيق أو يُعرض في رسالة خطأ:
 * معاملات الروابط السرية، وترويسة Bearer، والقيم السرية المعروفة حرفياً (من الإعداد والقاعدة).
 * لا يرمي استثناءً أبداً: يعمل داخل المسجّل.
 */
final class SecretRedactor
{
    /** @var list<string>|null */
    private static ?array $known = null;

    public static function clean(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        try {
            $text = preg_replace('/([?&](?:key|access_token|appsecret_proof)=)[^&\s"\']+/i', '$1***', $text) ?? $text;
            $text = preg_replace('/Bearer\s+[^\s"\',;]+/i', 'Bearer ***', $text) ?? $text;

            $known = self::knownSecrets();

            return $known ? str_replace($known, '***', $text) : $text;
        } catch (Throwable) {
            return $text;
        }
    }

    /** يُستدعى عند تغيّر الأسرار المحفوظة (العامل الطويل يحتفظ بالقائمة بين المهام) */
    public static function forget(): void
    {
        self::$known = null;
    }

    /** @return list<string> */
    private static function knownSecrets(): array
    {
        if (self::$known !== null) {
            return self::$known;
        }
        self::$known = [];   // أي سجل أثناء الجمع لا يعيد الجمع (لا تكرار لا نهائي)

        $values = [
            config('services.whatsapp.token'),
            config('services.whatsapp.app_secret'),
            ...array_values((array) config('services.llm', [])),
        ];

        // قراءة فقط: current() قد يُنشئ صفاً، والمسجّل لا يكتب في القاعدة
        $settings = self::attempt(fn () => WaBotSetting::query()->first());
        if ($settings) {
            foreach (['wa_token', 'wa_app_secret', 'api_key'] as $attribute) {
                $values[] = self::attempt(fn () => $settings->{$attribute});
            }
        }

        $values = array_filter($values, fn ($v) => is_string($v) && strlen($v) >= 8);
        // الأطول أولاً، فسرّ يحوي آخر لا يُستبدل نصفه
        usort($values, fn ($a, $b) => strlen($b) <=> strlen($a));

        return self::$known = array_values(array_unique($values));
    }

    private static function attempt(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }
}
