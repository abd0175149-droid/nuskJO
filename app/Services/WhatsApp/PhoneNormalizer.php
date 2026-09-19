<?php

namespace App\Services\WhatsApp;

use App\Models\Client;
use App\Models\User;

/**
 * توحيد الأرقام: نُسك يخزّن +962…/+966… وميتا ترسل 962… بلا +.
 * المطابقة النهائية على آخر 9 أرقام لتجاوز اختلاف صيغة الإدخال.
 */
class PhoneNormalizer
{
    public static function digits(?string $p): string
    {
        return preg_replace('/\D+/', '', (string) $p) ?? '';
    }

    /** صيغة موحّدة للتخزين والمطابقة (E.164 بلا +) */
    public static function canonical(?string $p): string
    {
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
        $d = self::digits($p);
        return strlen($d) > $n ? substr($d, -$n) : $d;
    }

    public static function same(?string $a, ?string $b): bool
    {
        $ta = self::tail($a);
        $tb = self::tail($b);
        return $ta !== '' && $ta === $tb;
    }

    /** البحث عن عميل بالهاتف (مطابقة آخر 9 أرقام) */
    public static function findClient(?string $phone): ?Client
    {
        $tail = self::tail($phone);
        if ($tail === '') return null;

        return Client::query()
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->get(['id', 'name', 'code', 'phone', 'account_id', 'employee_id'])
            ->first(fn ($c) => self::tail($c->phone) === $tail);
    }

    /** البحث عن مستخدم/موظف بالهاتف — لتمييز رسائل الموظفين */
    public static function findUser(?string $phone): ?User
    {
        $tail = self::tail($phone);
        if ($tail === '') return null;

        return User::query()
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->get(['id', 'name', 'phone', 'role_id', 'is_active'])
            ->first(fn ($u) => self::tail($u->phone) === $tail);
    }
}
