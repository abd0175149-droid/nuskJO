<?php

namespace App\Services\WhatsApp;

use App\Models\Setting;

/**
 * التعامل مع عميل بلا رقم (BSUID وحده)، على مراحل (whatsapp:identity-mode):
 *  store — تُحفظ رسالته ويُرفع «تدخّل» ويُنبَّه المدير، ولا إرسال إليه بعد.
 *  staff — الموظف والنظام يردّان عليه بالمعرّف (recipient)، والبوت لا.
 *  full  — والبوت أيضاً (بلا أي بيانات مالية: financialClientId يتطلب الرقم).
 */
final class IdentityMode
{
    public const STORE = 'store';

    public const STAFF = 'staff';

    public const FULL = 'full';

    public const MODES = [self::STORE, self::STAFF, self::FULL];

    public static function current(): string
    {
        $mode = (string) rescue(fn () => Setting::get('wa_identity_mode', self::STORE), self::STORE, false);

        return in_array($mode, self::MODES, true) ? $mode : self::STORE;
    }

    /** هل يُرسل هذا المصدر إلى عميل بلا رقم بالمعرّف */
    public static function allowsRecipient(string $source): bool
    {
        return match (self::current()) {
            self::FULL => true,
            self::STAFF => in_array($source, ['staff', 'system'], true),
            default => false,
        };
    }

    /** هل يردّ البوت على محادثة بلا رقم */
    public static function botServesWithoutPhone(): bool
    {
        return self::current() === self::FULL;
    }
}
