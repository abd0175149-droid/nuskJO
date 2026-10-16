<?php

namespace App\Events\WhatsApp;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * قبلت ميتا رسالة صادرة وأعادت معرّفها (الصف صار sent، أو سبقه ويبهوك الحالة). «غير مؤكد» لا يطلقه: حسمه لويبهوك
 * الحالة أو لمنظّف E19. لا يستطيع أي مستمع منع إرسال: يُطلق بعد خروج الرسالة.
 */
final class WaMessageSent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $messageId,
        public readonly int $conversationId,
        public readonly string $source,
        public readonly ?int $staffId,
    ) {}
}
