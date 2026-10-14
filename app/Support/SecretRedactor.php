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

    /** أشكال الأسرار المعروفة بالقيمة، أينما ظهرت */
    private const VALUE_PATTERNS = [
        '/AIza[0-9A-Za-z_\-]{35}/' => '***',                                   // مفتاح Google
        '/sk-ant-[A-Za-z0-9_\-]{16,}/' => '***',                               // مفتاح Anthropic
        '/\bEAA[A-Za-z0-9]{20,}/' => '***',                                     // رمز ميتا
        '/(Bearer\s+)[^\s"\',;]+/i' => '$1***',
        '/([?&](?:key|access_token|input_token|token|client_secret|appsecret_proof|hub[._]verify_token)=)[^&\s"\'<>]+/i' => '$1***',
        '/base64:[A-Za-z0-9+\/]{43}=/' => 'base64:***',                         // APP_KEY
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s' => '[PRIVATE KEY]',
    ];

    /** مفاتيح المصفوفات التي تُحجب قيمتها كلها (prompt_tokens وأمثالها لا تُحجب) */
    private const SECRET_KEY = '/^(password|secret|token|api_key|authorization|app_key|access_token|verify_token|wa_token|wa_app_secret)$|_secret$|_token$/i';

    /** @param  list<?string>  $also  أسرار يعرفها المستدعي ولم تُحفظ بعد (مفتاح يُفحص قبل حفظه) */
    public static function clean(string $text, array $also = []): string
    {
        if ($text === '') {
            return $text;
        }

        try {
            $text = preg_replace(array_keys(self::VALUE_PATTERNS), array_values(self::VALUE_PATTERNS), $text) ?? $text;

            $known = self::knownSecrets();
            $also = array_filter($also, fn ($v) => is_string($v) && strlen($v) >= 8);
            if ($also) {
                $known = array_merge(array_values($also), $known);
            }

            return $known ? str_replace($known, '***', $text) : $text;
        } catch (Throwable) {
            return $text;
        }
    }

    /** مصفوفة سياق: النصوص تُنقّح، وقيم المفاتيح السرية بالاسم تُحجب كلها، بالتكرار */
    public static function cleanArray(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) && $value !== null && $value !== '' && !is_array($value)) {
                $data[$key] = '***';
            } elseif (is_string($value)) {
                $data[$key] = self::clean($value);
            } elseif (is_array($value) && $depth < 5) {
                $data[$key] = self::cleanArray($value, $depth + 1);
            }
        }

        return $data;
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
