<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * تحديث كيان (شجرة الحسابات، الوكلاء): نوعه وفعله ورقمه فقط، على القناة الخاصة staff (E28) لكل موظف نشط مصادَق،
 * بعد أن كانت قناة عامة يراها كل حامل للمفتاح العام. يُطلق بعد اعتماد المعاملة.
 */
class DataUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $entity,    // 'agent', 'client', 'account', etc.
        public string $action,    // 'created', 'updated', 'deleted'
        public ?int $entityId = null
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('staff')];
    }

    public function broadcastAs(): string
    {
        return 'data.updated';
    }
}
