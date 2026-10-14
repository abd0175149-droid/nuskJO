<?php

namespace App\Services\WhatsApp\Outbound\Rules;

use App\Models\WaConversation;
use App\Services\WhatsApp\AdminTools;
use App\Services\WhatsApp\BotTools;

/** أسماء الأدوات وكتل الكود وصيغ الاستدعاء لا تصل العميل (stripToolLeak يزيلها قبل هذا؛ هذه شبكة ثانية) */
final class ToolLeakRule implements PolicyRule
{
    public function check(WaConversation $conv, array $texts, string $context): array
    {
        $names = implode('|', array_map('preg_quote', array_merge(BotTools::names(), AdminTools::names())));
        foreach ($texts as $t) {
            if (str_contains($t, '```')
                || preg_match('/(?<![A-Za-z_])(?:'.$names.')(?![A-Za-z_])/', $t)
                || preg_match('/\b(?:function_?call|tool_(?:use|call|code))\b/i', $t)) {
                return ['tool_leak'];
            }
        }

        return [];
    }
}
