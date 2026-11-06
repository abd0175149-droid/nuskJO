<?php

namespace App\Events\WhatsApp;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * تغيّرت حالة رقم على البوابة (ChannelState): الحالة ووصول QR جديد. معرّف الرقم وحالته فقط على القناة الخاصة staff،
 * فتحدّث صفحة الأرقام نفسها وتطلب QR بمفتاحها؛ لا QR ولا رقم هاتف في الحدث.
 */
class ChannelStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $channelId,
        public string $status,
        public bool $qr = false,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('staff')];
    }

    public function broadcastAs(): string
    {
        return 'wa.channel';
    }
}
