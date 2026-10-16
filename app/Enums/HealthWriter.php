<?php

namespace App\Enums;

/**
 * من يكتب انتقال صحة القناة (E22، ChannelHealth::ALLOWED). القيمة تُخزَّن في wa_health_source، ومعها legacy
 * للقفل الآلي القديم الذي نقلته الهجرة من wa_suspended (ليس كاتباً: لا انتقال بها).
 */
enum HealthWriter: string
{
    case Webhook = 'webhook';
    case Poll = 'poll';
    case SendError = 'send_error';
    case TokenCheck = 'token_check';
    case Manual = 'manual';

    public const LEGACY = 'legacy';

    public function label(): string
    {
        return match ($this) {
            self::Webhook => 'ويبهوك من ميتا',
            self::Poll => 'فحص دوري',
            self::SendError => 'فشل إرسال',
            self::TokenCheck => 'فحص الرمز',
            self::Manual => 'المدير',
        };
    }
}
