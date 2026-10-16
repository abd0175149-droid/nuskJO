<?php

namespace App\Events\WhatsApp;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * حُوّلت محادثة لموظف تحويلاً كاملاً (أُطفئ البوت فيها). يُطلق من BotTools::escalate وحدها، فلا يتكرر لتحويل
 * يمر بـ handoffFromCode. reason قيمة من App\Enums\HandoffReason. تحويل وضع العطل (E12) وfailSafe ليسا تحويلاً كاملاً.
 */
final class ConversationHandedOff implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $conversationId,
        public readonly ?int $assigneeId,
        public readonly ?int $quoteRequestId,
        public readonly string $reason,
    ) {}
}
