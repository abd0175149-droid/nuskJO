<?php

namespace App\Enums;

/**
 * سبب التحويل الكامل لموظف (E21، حمولة ConversationHandedOff وتدقيق WaHandoff). القيمة رمز ثابت بلا نص عميل.
 * erasure (E13) وbudget (E07) أُضيفا لأن الكود يحوّل بهما اليوم.
 */
enum HandoffReason: string
{
    case Quote = 'quote';
    case Booking = 'booking';
    case HandoffTool = 'handoff_tool';
    case GuardClaim = 'guard_claim';
    case RateLimit = 'rate_limit';
    case HumanRequest = 'human_request';
    case Erasure = 'erasure';
    case Budget = 'budget';

    /** عنوان الإشعار كما كان في escalate: الطلبان بعنوانيهما، وكل تحويل آخر بعنوان واحد */
    public function title(): string
    {
        return match ($this) {
            self::Quote => '💰 طلب تسعير جديد',
            self::Booking => '🧾 طلب تأكيد حجز',
            default => '🙋 تحويل محادثة واتساب',
        };
    }
}
