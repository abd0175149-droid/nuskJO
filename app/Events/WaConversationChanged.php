<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * تغيّرت محادثة في الصندوق (E28): يصل قنوات user.{id} الخاصة لمن يراها لحظة الحدث (InboxAudience بقواعد سياج E04)،
 * والحمولة {c, k} وحدها: رقم المحادثة ونوع التغيّر (message أو status أو meta). لا نص ولا هاتف ولا اسم؛ الواجهة
 * تعيد الجلب من مسارات REST المسيّجة، فأسوأ حال لمن سُحبت رؤيته رقم ثم 404.
 *
 * يُبث فوراً (لا طابور): InboxBroadcaster يطلقه بعد إرسال الرد أو بعد اعتماد المعاملة، داخل try/catch بقاطع.
 * لذلك لا ShouldDispatchAfterCommit هنا: تأجيله إلى الاعتماد كان سيُخرج نداء Reverb من حماية القاطع.
 * المستلمون خاصية خاصة: لا تُبث، ولا تظهر في الحمولة.
 */
final class WaConversationChanged implements ShouldBroadcastNow
{
    public const KINDS = ['message', 'status', 'meta'];

    /** @param list<int> $userIds */
    public function __construct(
        public readonly int $conversation_id,
        public readonly string $kind,
        private readonly array $userIds,
    ) {}

    /** PusherBroadcaster يقسّم القنوات على دفعات من 100 */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('user.'.$id), array_values(array_unique(array_map('intval', $this->userIds))));
    }

    public function broadcastAs(): string
    {
        return 'wa.changed';
    }

    /** @return array{c: int, k: string} */
    public function broadcastWith(): array
    {
        return ['c' => $this->conversation_id, 'k' => $this->kind];
    }
}
