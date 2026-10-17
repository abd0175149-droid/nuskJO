<?php

namespace App\Services\WhatsApp\Outbound\Rules;

use App\Models\WaConversation;

/** حقول داخلية (التكلفة والربح والمقاعد…) وJSON خام وآثار الأخطاء لا تخرج في رسالة آلية */
final class InternalFieldsRule implements PolicyRule
{
    private const WORDS = '/\b(?:cost|profit|margin|agent_id|room_rates|notes_internal|total_seats)\b|تكلف[ةه]|هامش\s*(?:ال)?ربح/iu';

    public function check(WaConversation $conv, array $texts, string $context): array
    {
        $out = [];
        foreach ($texts as $t) {
            if (preg_match(self::WORDS, $t)) {
                $out[] = 'internal_field';
            }
            if (preg_match('/\{\s*"[a-z_]+"\s*:/', $t)) {
                $out[] = 'raw_json';
            }
            if (preg_match('/(?:Stack trace|#\d+ \/var\/www|SQLSTATE|Exception:)/', $t)) {
                $out[] = 'trace';
            }
        }

        return array_values(array_unique($out));
    }
}
