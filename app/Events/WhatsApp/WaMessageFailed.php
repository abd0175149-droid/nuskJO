<?php

namespace App\Events\WhatsApp;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * صف صادر صار failed. origin: send (رفض ميتا المتزامن أو تعذّر الاتصال، داخل dispatch) أو webhook (حالة failed
 * تقدّمت فعلاً، StatusApplier). category وscope من MetaErrorCatalog (E18). رفض الأقفال قبل ميتا بلا صف ولا حدث،
 * وفشل الاتصال الذي يُحسم «غير مؤكد» لا يطلقه.
 */
final class WaMessageFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $messageId,
        public readonly int $conversationId,
        public readonly string $source,
        public readonly ?int $metaCode,
        public readonly ?string $category,
        public readonly ?string $scope,
        public readonly string $origin,
        public readonly ?int $staffId,
    ) {}
}
