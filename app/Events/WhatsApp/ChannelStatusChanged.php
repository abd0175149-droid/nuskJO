<?php

namespace App\Events\WhatsApp;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * تغيّرت حالة رقم على البوابة (ChannelState): الحالة ووصول QR جديد. معرّف الرقم وحالته فقط على القناة الخاصة staff،
 * فتحدّث صفحة الأرقام نفسها وتطلب QR بمفتاحها؛ لا QR ولا رقم هاتف في الحدث.
 */
final class ChannelStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $channelId,
        public readonly string $status,
        public readonly bool $qr = false,
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
