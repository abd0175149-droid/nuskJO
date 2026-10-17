<?php

namespace Tests\Concerns;

use App\Services\WhatsApp\Realtime\InboxBroadcaster;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Broadcast;

/**
 * محرك بث للاختبار (E28): يلتقط القنوات والاسم والحمولة كما يسلّمها Laravel للمحرك الحقيقي، بلا شبكة،
 * أو يرمي استثناءً كأن Reverb متعطّل. $this->broadcasts: قائمة ['channels' => [...], 'event' => ..., 'payload' => [...]].
 */
trait CapturesBroadcasts
{
    protected array $broadcasts = [];

    protected int $broadcastAttempts = 0;

    protected function captureBroadcasts(bool $failing = false): void
    {
        $test = $this;
        Broadcast::extend('capture', fn () => new class($test, $failing) extends Broadcaster
        {
            public function __construct(private $test, private bool $failing) {}

            public function auth($request)
            {
                return null;
            }

            public function validAuthenticationResponse($request, $result)
            {
                return null;
            }

            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->test->recordBroadcast(array_map(fn ($c) => (string) $c, $channels), $event, $payload, $this->failing);
            }
        });
        config(['broadcasting.default' => 'capture', 'broadcasting.connections.capture' => ['driver' => 'capture']]);
    }

    public function recordBroadcast(array $channels, string $event, array $payload, bool $failing): void
    {
        $this->broadcastAttempts++;
        if ($failing) {
            throw new BroadcastException('reverb unreachable');
        }
        unset($payload['socket']);
        $this->broadcasts[] = ['channels' => $channels, 'event' => $event, 'payload' => $payload];
    }

    /** @return list<array{channels: list<string>, event: string, payload: array}> أحداث wa.changed وحدها */
    protected function inboxBroadcasts(): array
    {
        return array_values(array_filter($this->broadcasts, fn ($b) => $b['event'] === 'wa.changed'));
    }

    /** المستخدمون الذين وصلهم wa.changed للمحادثة، مرتّبين */
    protected function recipientsOf(int $conversationId, ?string $kind = null): array
    {
        $ids = [];
        foreach ($this->inboxBroadcasts() as $b) {
            if ($b['payload']['c'] === $conversationId && ($kind === null || $b['payload']['k'] === $kind)) {
                foreach ($b['channels'] as $channel) {
                    $ids[] = (int) substr($channel, strlen('private-user.'));
                }
            }
        }
        sort($ids);

        return array_values(array_unique($ids));
    }

    protected function enableInboxRealtime(bool $on = true): void
    {
        config(['features.env.inbox_realtime' => $on]);
        // العلم يُقرأ مرة لكل طلب أو وظيفة (scoped)
        app()->forgetInstance(InboxBroadcaster::class);
    }
}
