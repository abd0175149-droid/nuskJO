<?php

namespace App\Enums;

use App\Support\FeatureFlags;

/**
 * حالة قناة الإرسال عند ميتا (E22): آلة حالات مصدرها ويبهوكات الحساب وhealth_status ورموز أخطاء الإرسال.
 * الإيقاف اليدوي (wa_suspended) محور مستقل لا يدخل هنا. القيمة تُخزَّن في wa_bot_settings.wa_health.
 */
enum WaHealth: string
{
    case Available = 'AVAILABLE';
    case Limited = 'LIMITED';
    case Blocked = 'BLOCKED';
    case TokenInvalid = 'TOKEN_INVALID';
    case Unknown = 'UNKNOWN';

    public static function of(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Unknown) : self::Unknown;
    }

    public function label(): string
    {
        return match ($this) {
            self::Available => 'تعمل',
            self::Limited => 'قيد جزئي — ردود العملاء تعمل',
            self::Blocked => 'محجوبة من ميتا',
            self::TokenInvalid => 'رمز غير صالح',
            self::Unknown => 'لم تُفحص بعد',
        };
    }

    /** ok · warn · critical · unknown */
    public function severity(): string
    {
        return match ($this) {
            self::Available => 'ok',
            self::Limited => 'warn',
            self::Blocked, self::TokenInvalid => 'critical',
            self::Unknown => 'unknown',
        };
    }

    /**
     * BLOCKED يمنع دائماً (امتداد للإيقاف الآلي القائم)، وTOKEN_INVALID بالعلم channel_health_block_on_token وحده
     * (ميتا ترفض الإرسال أصلاً، والمنع يوفّر توكنات النموذج). LIMITED لا يمنع ردود خدمة العملاء أبداً.
     */
    public function blocksSending(): bool
    {
        return match ($this) {
            self::Blocked => true,
            self::TokenInvalid => FeatureFlags::on('channel_health_block_on_token'),
            default => false,
        };
    }
}
