<?php

namespace App\Services\WhatsApp;

use App\Models\Client;
use App\Models\User;

/**
 * توحيد الأرقام: نُسك يخزّن +962…/+966… وميتا ترسل 962… بلا +.
 * الربط الآلي (عميل، موظف) بالرقم الكامل الموحَّد وحده (E11): آخر 9 أرقام قد تطابق رقماً من بلد آخر.
 * معرّف ميتا (BSUID مثل «JO.123…») لا يصير رقماً أبداً: نص فيه حرف أو نقطة يعطي ''.
 */
class PhoneNormalizer
{
    public static function digits(?string $p): string
    {
        return preg_replace('/\D+/', '', (string) $p) ?? '';
    }

    /** أرقام فقط بصيغة هاتف (6–15 رقماً، و+ اختياري): لا أحرف ولا نقاط */
    public static function isPhoneLike(?string $p): bool
    {
        return is_string($p) && preg_match('/^\+?\d{6,15}$/', $p) === 1;
    }

    /** نص فيه حرف أو نقطة ليس رقماً (معرّف BSUID، أو «079… (أحمد)») */
    private static function hasLetters(?string $p): bool
    {
        return preg_match('/[\p{L}.]/u', (string) $p) === 1;
    }

    /** صيغة موحّدة للتخزين والمطابقة (E.164 بلا +) */
    public static function canonical(?string $p): string
    {
        if (self::hasLetters($p)) return '';
        $d = self::digits($p);
        if ($d === '') return '';

        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        // محلي أردني 07XXXXXXXX → 9627XXXXXXXX
        if (strlen($d) === 10 && str_starts_with($d, '07')) return '962' . substr($d, 1);
        // محلي سعودي 05XXXXXXXX → 9665XXXXXXXX
        if (strlen($d) === 10 && str_starts_with($d, '05')) return '966' . substr($d, 1);
        // بلا صفر ولا مفتاح
        if (strlen($d) === 9 && str_starts_with($d, '7')) return '962' . $d;
        if (strlen($d) === 9 && str_starts_with($d, '5')) return '966' . $d;

        return $d;
    }

    /** آخر n أرقام — أساس المطابقة */
    public static function tail(?string $p, int $n = 9): string
    {
        if (self::hasLetters($p)) return '';
        $d = self::digits($p);
        return strlen($d) > $n ? substr($d, -$n) : $d;
    }

    public static function same(?string $a, ?string $b): bool
    {
        $ta = self::tail($a);
        $tb = self::tail($b);
        return $ta !== '' && $ta === $tb;
    }

    /**
     * رقم محفوظ يصلح للربط الآلي: دولي (+ أو 00 أو بمفتاح الدولة) أو أردني محلي (07… أو 7…).
     * المحلي 05… ملتبس (السعودية والإمارات) فلا يُربط آلياً؛ يربطه موظف يدوياً.
     */
    public static function autoLinkable(?string $stored): bool
    {
        $raw = trim((string) $stored);
        if ($raw === '' || self::hasLetters($raw)) return false;
        $d = self::digits($raw);

        return str_starts_with($raw, '+') || str_starts_with($d, '00') || strlen($d) >= 11 || preg_match('/^0?7\d{8}$/', $d) === 1;
    }

    /** عميل بالرقم الكامل الموحَّد؛ أكثر من عميل بالرقم نفسه = لا ربط آلي (null) */
    public static function findClient(?string $phone): ?Client
    {
        $matches = self::matches(Client::query(), $phone, ['id', 'name', 'code', 'phone', 'account_id', 'employee_id']);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** مستخدم/موظف بالرقم الكامل الموحَّد — لتمييز رسائل الموظفين */
    public static function findUser(?string $phone): ?User
    {
        $matches = self::matches(User::query(), $phone, ['id', 'name', 'phone', 'role_id', 'is_active']);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private static function matches($query, ?string $phone, array $columns)
    {
        $canonical = self::canonical($phone);
        if ($canonical === '' || strlen($canonical) < 8) {
            return collect();
        }

        // بلا تصفية LIKE في القاعدة: رقم محفوظ بمسافات أو شرطات («123 4567») يفلت منها
        return $query->whereNotNull('phone')->where('phone', '!=', '')
            ->get($columns)
            ->filter(fn ($row) => self::autoLinkable($row->phone) && self::canonical($row->phone) === $canonical)
            ->values();
    }
}
