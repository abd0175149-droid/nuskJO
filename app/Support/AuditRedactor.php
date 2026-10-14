<?php

namespace App\Support;

/**
 * لا أسرار ولا نصوص رسائل ولا جوازات ولا هواتف عملاء في audit_logs.changes: المفاتيح الحساسة تُستبدل قيمتها،
 * والقيم النصية تمر على SecretRedactor (رموز ميتا ومفاتيح النماذج)، والحمولة الكبيرة تُقص.
 * الموجّه وما شابهه يُسجَّل بطوله وبصمته فقط (fingerprint)، لا الأسرار (بصمة سر ضعيف تُكسر).
 */
final class AuditRedactor
{
    private const KEY = '/(pass(word)?|token|secret|api_?key|passport|national_?id|^body$|caption|transcript|^phone$|_phone$|wa_id|recipient_id|^text$)/i';

    public static function clean(?array $changes): ?array
    {
        if ($changes === null) {
            return null;
        }
        $clean = self::walk($changes);

        $max = (int) config('audit.changes_max_bytes', 16384);
        if (strlen((string) json_encode($clean, JSON_UNESCAPED_UNICODE)) > $max) {
            $clean = self::truncate($clean);
            $clean['truncated'] = true;
        }

        return $clean;
    }

    /** a***@domain.com: يكفي لمعرفة الحساب المستهدف دون كشف البريد */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***'.($domain !== '' ? '@'.$domain : '');
    }

    /** @return array{len: int, sha1: string} */
    public static function fingerprint(?string $value): array
    {
        return ['len' => mb_strlen((string) $value), 'sha1' => substr(sha1((string) $value), 0, 12)];
    }

    private static function walk(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::KEY, $key)) {
                $out[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $out[$key] = $depth > 8 ? '[deep]' : self::walk($value, $depth + 1);
            } elseif (is_string($value)) {
                $out[$key] = SecretRedactor::clean($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function truncate(array $data): array
    {
        array_walk_recursive($data, function (&$value) {
            if (is_string($value) && mb_strlen($value) > 500) {
                $value = mb_substr($value, 0, 500).'…';
            }
        });

        return $data;
    }
}
