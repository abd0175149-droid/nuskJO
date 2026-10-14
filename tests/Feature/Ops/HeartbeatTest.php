<?php

namespace Tests\Feature\Ops;

use App\Jobs\OpsHeartbeat;
use App\Jobs\ProcessWhatsAppReply;
use App\Services\Ops\OpsHealth;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** نبضة المجدول كل دقيقة، ونبضة لكل عامل على طابوره، ولا تتراكم نبضات عامل ميت. */
class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $description): ?Event
    {
        return collect(app(Schedule::class)->events())->first(fn (Event $e) => $e->description === $description);
    }

    public function test_the_scheduler_writes_its_beat_every_minute(): void
    {
        $event = $this->event('ops:schedule-beat');
        $this->assertSame('* * * * *', $event->expression);

        $event->run(app());

        $this->assertSame(now()->getTimestamp(), Cache::get(OpsHealth::SCHEDULE_BEAT));
    }

    public function test_each_worker_has_a_beat_on_its_own_queue(): void
    {
        $this->assertSame(['default', 'wa-replies'], OpsHealth::queues());
        foreach (OpsHealth::queues() as $queue) {
            $this->assertSame('* * * * *', $this->event("ops:queue-beat:{$queue}")?->expression, $queue);
        }

        Queue::fake();
        $this->event('ops:queue-beat:wa-replies')->run(app());
        Queue::assertPushedOn(ProcessWhatsAppReply::queueName(), OpsHeartbeat::class);
    }

    public function test_the_job_writes_the_beat_of_its_queue(): void
    {
        OpsHeartbeat::dispatchSync('wa-replies');

        $this->assertSame(now()->getTimestamp(), Cache::get(OpsHealth::beatKey('wa-replies')));
        $this->assertNull(Cache::get(OpsHealth::beatKey('default')));
    }

    public function test_a_dead_worker_does_not_pile_up_beats(): void
    {
        Queue::fake();

        OpsHeartbeat::dispatch('default');
        OpsHeartbeat::dispatch('default');
        OpsHeartbeat::dispatch('wa-replies');

        Queue::assertPushed(OpsHeartbeat::class, 2);
    }

    public function test_failed_jobs_are_pruned_after_a_week(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains((string) $e->command, 'queue:prune-failed'));

        $this->assertStringContainsString('--hours=168', $event->command);
        $this->assertSame('0 0 * * *', $event->expression);
    }
}
