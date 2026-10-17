<?php

namespace App\Services\WhatsApp\Outbound\Rules;

use App\Models\WaConversation;

interface PolicyRule
{
    /**
     * @param  list<string>  $texts  كل حقول النص في الرسالة
     * @return list<string> رموز الأسباب
     */
    public function check(WaConversation $conv, array $texts, string $context): array;
}
