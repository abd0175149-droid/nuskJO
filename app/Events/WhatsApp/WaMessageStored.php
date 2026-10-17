<?php

namespace App\Events\WhatsApp;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * رسالة واردة حُفظت (نص أو وسائط أو تفاعل أو رسالة نظام من ميتا). يُطلق بعد اعتماد معاملة الحفظ وحدها،
 * ولا يُطلق لتكرار wamid. الحمولة معرّفات ونوع فقط (E21): من يحتاج النص يقرؤه من القاعدة بصلاحيته.
 */
final class WaMessageStored implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $messageId,
        public readonly int $conversationId,
        public readonly string $msgType,
    ) {}
}
