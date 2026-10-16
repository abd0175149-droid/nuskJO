<?php

namespace App\Events\WhatsApp;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * تقدّمت حالة رسالة صادرة فعلاً (sent ثم delivered ثم read ثم played، أو failed فوق ما دون delivered).
 * إعادة ميتا للحالة نفسها، أو حالة أدنى متأخرة، لا تطلقه. metaTs وقت ميتا للحالة (ثوانٍ).
 */
final class WaMessageStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $messageId,
        public readonly int $conversationId,
        public readonly string $status,
        public readonly int $metaTs,
        public readonly ?int $metaCode,
    ) {}
}
