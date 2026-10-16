<?php

namespace Tests\Feature\Ops;

use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Ops\OpsMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * لقطة whatsapp:ops-check (E24): أعداد صحيحة من الطوابير والمهام والإرسال بفئات E18، ويوم عمّان حدّاً للصادر،
 * واستعلامات تجميعية وحدها حتى على جدول كبير.
 */
class OpsMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function job(string $queue, int $availableAgo, ?int $reservedAgo = null): void
    {
        $now = now()->getTimestamp();
        DB::table('jobs')->insert(['queue' => $queue, 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => $reservedAgo !== null ? $now - $reservedAgo : null, 'available_at' => $now - $availableAgo, 'created_at' => $now - $availableAgo]);
    }

    private function failedJob(Carbon $at): void
    {
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'RuntimeException: boom', 'failed_at' => $at]);
    }

    private function out(array $attributes): WaMessage
    {
        return WaMessage::factory()->outbound()->create($attributes);
    }

    public function test_the_snapshot_counts_queues_jobs_and_failures_by_category(): void
    {
        $this->job('default', 600);
        $this->job('default', 120);
        $this->job('wa-replies', 30);
        $this->job('default', 900, reservedAgo: 500);   // عامل مات وهو يعمل (retry_after 240)
        $this->job('default', -60);                     // مؤجلة: ليست متاحة بعد
        $this->failedJob(now()->subMinutes(10));
        $this->failedJob(now()->subMinutes(40));
        $this->failedJob(now()->subHours(3));

        $conv = WaConversation::factory()->windowOpen()->create();
        foreach (['window', 'auth', 'throttle', null] as $category) {
            $this->out(['conversation_id' => $conv->id, 'status' => 'failed', 'error_category' => $category]);
        }
        $this->out(['conversation_id' => $conv->id, 'status' => 'failed', 'error_category' => 'auth', 'created_at' => now()->subHours(2)]);
        $this->out(['conversation_id' => $conv->id, 'status' => 'unknown']);

        $snap = (new OpsMetrics)->snapshot();

        $this->assertSame(['jobs' => 2, 'oldest_age_s' => 600, 'reserved_stuck' => 1], $snap['queue']['default']);
        $this->assertSame(['jobs' => 1, 'oldest_age_s' => 30, 'reserved_stuck' => 0], $snap['queue']['wa-replies']);
        $this->assertSame(['jobs' => 0, 'oldest_age_s' => null, 'reserved_stuck' => 0], $snap['queue']['wa-media']);
        $this->assertSame(['1h' => 2, '24h' => 3], $snap['failed_jobs']);
        $this->assertSame(1, $snap['outbound_failed_1h']['window']);
        $this->assertSame(1, $snap['outbound_failed_1h']['auth']);
        $this->assertSame(1, $snap['outbound_failed_1h']['throttle']);
        $this->assertSame(1, $snap['outbound_failed_1h']['unknown'], 'الفئة الفارغة تُعدّ unknown');
        $this->assertSame(0, $snap['outbound_failed_1h']['transient']);
        $this->assertSame(1, $snap['unknown_sends_1h']);
        $this->assertSame([], $snap['errors']);
        $this->assertSame(600, OpsMetrics::oldestQueueAge($snap));
    }

    public function test_outbound_today_follows_the_amman_day(): void
    {
        // 08:00 عمّان = 05:00 UTC
        $this->travelTo(Carbon::parse('2026-10-16 05:00:00', 'UTC'));
        $conv = WaConversation::factory()->windowOpen()->create();
        $this->out(['conversation_id' => $conv->id, 'source' => 'bot', 'created_at' => Carbon::parse('2026-10-15 21:30:00', 'UTC')]);   // 00:30 عمّان اليوم
        $this->out(['conversation_id' => $conv->id, 'source' => 'staff', 'created_at' => Carbon::parse('2026-10-15 20:59:00', 'UTC')]); // 23:59 عمّان أمس
        $this->out(['conversation_id' => $conv->id, 'source' => 'staff', 'created_at' => now()]);
        $this->out(['conversation_id' => $conv->id, 'source' => 'bot', 'status' => 'failed', 'created_at' => now()]);   // غير مسلَّمة
        $this->out(['conversation_id' => $conv->id, 'source' => 'test', 'created_at' => now()]);   // E23: سطر مستقل

        $today = (new OpsMetrics)->snapshot()['outbound_today'];

        $this->assertSame('2026-10-16', $today['day']);
        $this->assertSame(['bot' => 1, 'staff' => 1], $today['by_source']);
        $this->assertSame(2, $today['total']);
        $this->assertSame(1, $today['test']);
    }

    public function test_waiting_humans_latency_and_service_billable_month(): void
    {
        $this->travelTo(Carbon::parse('2026-10-16 05:00:00', 'UTC'));
        WaConversation::factory()->create(['needs_attention' => true, 'last_inbound_at' => now()->subMinutes(45)]);
        WaConversation::factory()->create(['needs_attention' => true, 'last_inbound_at' => now()->subMinutes(10)]);   // أحدث من 30 دقيقة
        WaConversation::factory()->create(['needs_attention' => true, 'last_inbound_at' => now()->subDays(2)]);      // نافذة مغلقة
        foreach ([1000, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 9000, 70000] as $ms) {
            WaBotUsage::create(['source' => 'live', 'model' => 'm', 'latency_ms' => $ms, 'created_at' => now()->subMinutes(5)]);
        }
        WaBotUsage::create(['source' => 'live', 'model' => 'm', 'latency_ms' => 99000, 'created_at' => now()->subHours(2)]);
        $conv = WaConversation::factory()->windowOpen()->create();
        $this->out(['conversation_id' => $conv->id, 'pricing_billable' => true, 'pricing_category' => 'service']);
        $this->out(['conversation_id' => $conv->id, 'pricing_billable' => true, 'pricing_category' => 'service', 'created_at' => Carbon::parse('2026-09-30 21:30:00', 'UTC')]);  // 1 أكتوبر عمّان
        $this->out(['conversation_id' => $conv->id, 'pricing_billable' => true, 'pricing_category' => 'service', 'created_at' => Carbon::parse('2026-09-30 20:00:00', 'UTC')]);  // سبتمبر
        $this->out(['conversation_id' => $conv->id, 'pricing_billable' => false, 'pricing_category' => 'service']);

        $snap = (new OpsMetrics)->snapshot();

        $this->assertSame(1, $snap['waiting_human']);
        $this->assertSame(70000, $snap['bot_latency_p90_ms_1h'], 'offset floor(0.9×10)=9 في القيم المرتبة');
        $this->assertSame(2, $snap['service_billable_month']);
        $this->assertNotNull($snap['channel_health']);
    }

    public function test_the_snapshot_is_aggregate_only_and_fast_on_a_large_table(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $now = now()->format('Y-m-d H:i:s');
        $statuses = ['sent', 'delivered', 'failed', 'read', 'unknown'];
        for ($chunk = 0; $chunk < 100; $chunk++) {
            $rows = [];
            for ($i = 0; $i < 500; $i++) {
                $n = $chunk * 500 + $i;
                $rows[] = ['conversation_id' => $conv->id, 'direction' => $n % 3 ? 'out' : 'in', 'source' => ['bot', 'staff', 'customer'][$n % 3],
                    'msg_type' => 'text', 'body' => 'x', 'status' => $statuses[$n % 5], 'error_category' => $n % 5 === 2 ? 'transient' : null,
                    'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('wa_messages')->insert($rows);
        }
        $this->assertSame(50000, WaMessage::count());

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = strtolower($q->sql);
        });
        $started = microtime(true);
        $snap = (new OpsMetrics)->snapshot();
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(1.0, $elapsed, 'اللقطة على 50,000 رسالة أبطأ من ثانية');
        $this->assertSame([], $snap['errors']);
        $selects = array_filter($queries, fn ($sql) => str_starts_with(ltrim($sql), 'select'));
        $this->assertNotEmpty($selects);
        foreach ($selects as $sql) {
            $this->assertMatchesRegularExpression('/count\(|sum\(|min\(|max\(|limit 1\b|sqlite_master|pragma_/', $sql, "استعلام غير تجميعي: {$sql}");
        }
    }
}
