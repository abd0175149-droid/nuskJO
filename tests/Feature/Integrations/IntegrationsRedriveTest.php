<?php

namespace Tests\Feature\Integrations;

use App\Jobs\DeliverIntegrationWebhook;
use App\Models\IntegrationOutboxRow;
use App\Models\Notification;
use App\Models\User;
use App\Services\Integrations\IntegrationsHealth;
use App\Services\Ops\OpsHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsIntegrations;
use Tests\TestCase;

/**
 * المُصالِح والتقليم وسطر الصحة (E40.6): «قيد التسليم» العالق يعود، والمعلّق الذي حلّ موعده بلا وظيفة يُجدول مرة واحدة
 * ولو شُغّل الأمر مرتين، ولا جدولة فوق عامل متوقف، والتنبيه مرة في اليوم، والتقليم بمدتيه.
 */
class IntegrationsRedriveTest extends TestCase
{
    use BuildsIntegrations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableOutbox();
        Queue::fake();
    }

    private function row(array $attributes = [], ?int $endpointId = null): IntegrationOutboxRow
    {
        $row = new IntegrationOutboxRow;
        $row->forceFill(array_merge([
            'endpoint_id' => $endpointId ?? $this->endpoint()->id,
            'event' => 'invoice.approved',
            'idempotency_key' => 'k'.uniqid(),
            'payload' => ['type' => 'invoice.approved', 'id' => 'k', 'timestamp' => now()->format(DATE_ATOM), 'schema' => 1, 'data' => []],
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => now(),
            'dispatched_at' => null,
        ], $attributes))->save();

        return $row;
    }

    public function test_a_row_stuck_delivering_for_15_minutes_returns_to_pending_and_is_scheduled(): void
    {
        $row = $this->row(['status' => 'delivering', 'attempts' => 1, 'dispatched_at' => now()->subMinutes(15)]);
        DB::table('integration_outbox')->where('id', $row->id)->update(['updated_at' => now()->subMinutes(15)]);
        $fresh = $this->row(['status' => 'delivering', 'attempts' => 1, 'dispatched_at' => now()]);

        $this->artisan('integrations:redrive')->assertSuccessful();

        $this->assertSame('pending', $row->fresh()->status);
        $this->assertSame(1, $row->fresh()->attempts);
        $this->assertSame('delivering', $fresh->fresh()->status, 'الحديث يُترك لعامله');
        Queue::assertPushed(DeliverIntegrationWebhook::class, fn ($job) => $job->rowId === $row->id);
    }

    public function test_a_due_pending_row_without_a_live_job_is_scheduled_once_even_if_run_twice(): void
    {
        $orphan = $this->row(['next_attempt_at' => now()->subMinute(), 'dispatched_at' => null]);
        $waiting = $this->row(['next_attempt_at' => now()->subMinute(), 'dispatched_at' => now()->subMinute()]);   // وظيفته المؤجلة حية
        $future = $this->row(['next_attempt_at' => now()->addHour(), 'dispatched_at' => null]);
        $paused = $this->row(['next_attempt_at' => now()->subMinute()], $this->endpoint(['is_active' => false])->id);

        $this->artisan('integrations:redrive')->assertSuccessful();
        $this->artisan('integrations:redrive')->assertSuccessful();

        Queue::assertPushed(DeliverIntegrationWebhook::class, 1);
        Queue::assertPushed(DeliverIntegrationWebhook::class, fn ($job) => $job->rowId === $orphan->id);
        foreach ([$waiting, $future, $paused] as $row) {
            Queue::assertNotPushed(DeliverIntegrationWebhook::class, fn ($job) => $job->rowId === $row->id);
        }

        // وظيفة تأخرت عن موعدها 10 دقائق: ضاعت، فتُجدول من جديد
        $this->travel(11)->minutes();
        $this->artisan('integrations:redrive')->assertSuccessful();
        Queue::assertPushed(DeliverIntegrationWebhook::class, fn ($job) => $job->rowId === $waiting->id);
    }

    public function test_it_does_not_pile_jobs_on_a_stopped_worker_and_alerts_once_a_day(): void
    {
        $admin = User::factory()->admin()->create();
        $this->row(['next_attempt_at' => now()->subMinute()]);
        DB::table('jobs')->insert(['queue' => 'integrations', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(20)->getTimestamp(), 'created_at' => now()->subMinutes(20)->getTimestamp()]);

        $this->artisan('integrations:redrive')->assertSuccessful();
        $this->artisan('integrations:redrive')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'like', '%عامل التكاملات%')->count());
    }

    public function test_dead_rows_and_a_large_backlog_alert_once_a_day(): void
    {
        $admin = User::factory()->admin()->create();
        config(['integrations.pending_alert' => 2]);
        $endpoint = $this->endpoint();
        $this->row(['status' => 'dead'], $endpoint->id);
        foreach (range(1, 3) as $i) {
            $this->row(['next_attempt_at' => now()->addHour()], $endpoint->id);
        }

        $this->artisan('integrations:redrive')->assertSuccessful();
        $this->artisan('integrations:redrive')->assertSuccessful();

        $this->assertSame(2, Notification::where('user_id', $admin->id)->count(), 'فاشل ومتراكم، كلٌّ مرة');
    }

    public function test_with_the_flag_off_it_does_nothing(): void
    {
        config(['features.env.integration_outbox' => false]);
        $this->row(['next_attempt_at' => now()->subMinute()]);

        $this->artisan('integrations:redrive')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_prune_keeps_recent_rows_and_removes_old_delivered_and_dead(): void
    {
        $endpoint = $this->endpoint();
        $keep = [
            $this->row(['status' => 'delivered'], $endpoint->id),
            $this->row(['status' => 'dead'], $endpoint->id),
            $this->row(['status' => 'pending'], $endpoint->id),
        ];
        $oldDelivered = $this->row(['status' => 'delivered'], $endpoint->id);
        $oldDeadKept = $this->row(['status' => 'dead'], $endpoint->id);
        $oldDead = $this->row(['status' => 'dead'], $endpoint->id);
        DB::table('integration_outbox')->where('id', $oldDelivered->id)->update(['updated_at' => now()->subDays(31)]);
        DB::table('integration_outbox')->where('id', $oldDeadKept->id)->update(['updated_at' => now()->subDays(31)]);
        DB::table('integration_outbox')->where('id', $oldDead->id)->update(['updated_at' => now()->subDays(91)]);

        config(['features.env.integration_outbox' => false]);   // التقليم لا يتوقف بالعلم
        $this->artisan('integrations:prune')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [...array_map(fn ($r) => $r->id, $keep), $oldDeadKept->id],
            IntegrationOutboxRow::pluck('id')->all(),
        );
    }

    public function test_the_health_line_counts_without_urls_and_is_hidden_before_use(): void
    {
        config(['features.env.integration_outbox' => false]);
        $this->assertNull(IntegrationsHealth::summary());

        $this->enableOutbox();
        $endpoint = $this->endpoint();
        $this->endpoint(['is_active' => false, 'paused_reason' => '20 فشلاً متتالياً']);
        $this->row(['status' => 'pending'], $endpoint->id);
        $this->row(['status' => 'dead'], $endpoint->id);
        DB::table('integration_outbox')->where('status', 'pending')->update(['created_at' => now()->subMinutes(42)]);

        $line = app(OpsHealth::class)->snapshot()['integrations'];
        $this->assertSame(['enabled' => true, 'endpoints' => 2, 'active' => 1, 'paused' => 1, 'pending' => 1, 'oldest_pending_minutes' => 42, 'dead_24h' => 1], $line);
    }

    public function test_both_commands_are_scheduled_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'integrations:'));

        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn ($e) => $e->withoutOverlapping));
    }
}
