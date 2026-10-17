<?php

namespace App\Services\WhatsApp\Outbound\Rules;

use App\Models\WaConversation;
use App\Models\WaOptout;

/** رسالة استباقية (بث أو تذكير) لرقم أوقف الرسائل تُحجب. الرد داخل المحادثة لا يمر بها؛ لا سياق استباقياً اليوم */
final class OptOutRule implements PolicyRule
{
    public const PROACTIVE = ['broadcast', 'reminder', 'campaign'];

    public function check(WaConversation $conv, array $texts, string $context): array
    {
        if (!in_array($context, self::PROACTIVE, true) || !$conv->hasPhone()) {
            return [];
        }

        return WaOptout::where('phone', $conv->phone)->exists() ? ['opted_out'] : [];
    }
}
