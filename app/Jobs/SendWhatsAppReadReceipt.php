<?php

namespace App\Jobs;

use App\Models\WaConversation;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * إيصال القراءة بعد رد الموظف أو فتحه الصريح للمحادثة (E35). يُرسل بـ dispatchAfterResponse: ينفَّذ بعد أن يصل
 * الصندوقَ رده (php-fpm بعد fastcgi_finish_request)، فلا يتأخر المتحكم بنداء Graph. محاولة واحدة، وفشلها سطر وعدّاد.
 * upto: أعلى وارد كان موجوداً لحظة الرد أو الفتح، فلا يُعلَّم مقروءاً ما وصل بعده.
 */
class SendWhatsAppReadReceipt implements ShouldQueue
{
    use Queueable;

    public int $timeout = 10;

    public int $tries = 1;

    public function __construct(
        public int $conversationId,
        public ?int $uptoMessageId,
        public string $reason,
    ) {}

    public function handle(): void
    {
        $conv = WaConversation::find($this->conversationId);
        if ($conv) {
            WhatsAppChannel::markRead($conv, false, $this->uptoMessageId);
        }
    }
}
