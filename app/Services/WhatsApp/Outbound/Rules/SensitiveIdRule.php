<?php

namespace App\Services\WhatsApp\Outbound\Rules;

use App\Models\WaConversation;

/**
 * معرّفات حساسة تمنع سياسة واتساب طلبها أو مشاركتها: رقم بطاقة يجتاز Luhn، وIBAN يجتاز mod-97 (عدا حسابات الشركة
 * في whatsapp.outbound_policy.allow_ibans)، ورمز جواز، ورقم وطني من 10 خانات يبدأ بـ 2 أو 9.
 */
final class SensitiveIdRule implements PolicyRule
{
    public function check(WaConversation $conv, array $texts, string $context): array
    {
        $out = [];
        foreach ($texts as $t) {
            $t = self::asciiDigits($t);
            if (preg_match_all('/(?<!\d)\d(?:[ \-]?\d){12,18}(?!\d)/', $t, $m)) {
                foreach ($m[0] as $c) {
                    if (self::luhn(preg_replace('/\D/', '', $c))) {
                        $out[] = 'card';
                        break;
                    }
                }
            }
            if (preg_match_all('/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]){11,30}\b/', $t, $m)) {
                foreach ($m[0] as $c) {
                    $iban = str_replace(' ', '', $c);
                    if (self::mod97($iban) && !in_array($iban, self::allowedIbans(), true)) {
                        $out[] = 'iban';
                        break;
                    }
                }
            }
            if (preg_match('/\b[A-Z]{1,2}\d{6,8}\b/', $t)) {
                $out[] = 'passport';
            }
            if (preg_match('/(?<![\d+])[29]\d{9}(?!\d)/', $t)) {
                $out[] = 'national_id';
            }
        }

        return array_values(array_unique($out));
    }

    public static function luhn(string $digits): bool
    {
        $len = strlen($digits);
        if ($len < 13 || $len > 19) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < $len; $i++) {
            $d = (int) $digits[$len - 1 - $i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return $sum % 10 === 0;
    }

    public static function mod97(string $iban): bool
    {
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }
        $moved = substr($iban, 4).substr($iban, 0, 4);
        $numeric = preg_replace_callback('/[A-Z]/', fn ($m) => (string) (ord($m[0]) - 55), $moved);
        $rem = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $rem = (int) ($rem.$chunk) % 97;
        }

        return $rem === 1;
    }

    /** @return list<string> */
    private static function allowedIbans(): array
    {
        return array_map(fn ($i) => strtoupper(str_replace(' ', '', (string) $i)), (array) config('whatsapp.outbound_policy.allow_ibans', []));
    }

    private static function asciiDigits(string $t): string
    {
        return strtr($t, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }
}
