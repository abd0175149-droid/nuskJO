<?php

namespace App\Jobs;

use App\Services\PushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * إرسال إشعار الجهاز في الطابور: لا يبطئ العملية التي أنشأت الإشعار،
 * ولا يُفشلها إن تعطّل Firebase. يُرسل بعد اعتماد المعاملة فقط.
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [10, 60];
    public int $timeout = 30;

    public function __construct(
        public int $userId,
        public string $title,
        public string $body,
        public ?string $url = null,
        public ?string $tag = null,
    ) {
        $this->afterCommit();
    }

    public function handle(): void
    {
        PushService::toUser($this->userId, $this->title, $this->body, $this->url, $this->tag);
    }
}
