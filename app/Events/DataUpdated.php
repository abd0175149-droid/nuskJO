<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class DataUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $entity,    // 'agent', 'client', 'account', etc.
        public string $action,    // 'created', 'updated', 'deleted'
        public ?int $entityId = null
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('nusuk-updates')];
    }

    public function broadcastAs(): string
    {
        return 'data.updated';
    }
}
