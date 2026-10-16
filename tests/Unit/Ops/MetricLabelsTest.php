<?php

namespace Tests\Unit\Ops;

use App\Http\Controllers\InternalMetricsController;
use App\Services\Ops\OpsMetrics;
use Tests\TestCase;

/**
 * تسميات /internal/metrics (E24) من قوائم ثابتة في الكود وحدها: حتى لو حملت اللقطة اسماً أو رقماً في مفتاح
 * (مصدر أو فئة أو طابور أو حالة غريبة) لا يظهر في المخرج.
 */
class MetricLabelsTest extends TestCase
{
    public function test_every_label_value_comes_from_a_fixed_list(): void
    {
        $leak = '16505551234';
        $snap = [
            'at' => 1760000000,
            'queue' => ['default' => ['jobs' => 2, 'oldest_age_s' => 30, 'reserved_stuck' => 0], $leak => ['jobs' => 9, 'oldest_age_s' => 9, 'reserved_stuck' => 0]],
            'failed_jobs' => ['1h' => 1, '24h' => 2, $leak => 3],
            'outbound_failed_1h' => ['window' => 1, 'Sheena Nelson' => 4],
            'unknown_sends_1h' => 0,
            'webhook_failed_24h' => 0,
            'waiting_human' => 1,
            'bot_latency_p90_ms_1h' => 1200,
            'outbound_today' => ['by_source' => ['bot' => 3, $leak => 7, 'عميل' => 1], 'total' => 11, 'test' => 0],
            'service_billable_month' => 5,
            'channel_health' => 'AVAILABLE',
            'scheduler_beat_age_s' => 12,
        ];

        $out = InternalMetricsController::render($snap);

        $this->assertStringNotContainsString($leak, $out);
        $this->assertStringNotContainsString('Sheena', $out);
        $this->assertStringNotContainsString('عميل', $out);

        $allowed = [
            'queue' => OpsMetrics::queues(),
            'window' => ['1h', '24h'],
            'source' => InternalMetricsController::SOURCES,
            'category' => OpsMetrics::categories(),
            'state' => InternalMetricsController::HEALTH_STATES,
        ];
        preg_match_all('/(\w+)="([^"]*)"/', $out, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        foreach ($m as [, $label, $value]) {
            $this->assertArrayHasKey($label, $allowed, "تسمية غير معروفة: {$label}");
            $this->assertContains($value, $allowed[$label], "{$label}={$value} خارج القائمة الثابتة");
        }
        $this->assertStringContainsString('nuskjo_wa_channel_health{state="AVAILABLE"} 1', $out);
        $this->assertStringContainsString('nuskjo_wa_channel_health{state="BLOCKED"} 0', $out);
    }

    public function test_every_sample_line_is_valid_exposition_format(): void
    {
        $out = InternalMetricsController::render(['at' => 1, 'queue' => [], 'failed_jobs' => ['1h' => 0, '24h' => 0], 'outbound_today' => []]);

        foreach (array_filter(explode("\n", $out)) as $line) {
            $this->assertMatchesRegularExpression('/^(# (HELP|TYPE) nuskjo_\w+ .+|nuskjo_\w+(\{[a-z_]+="[A-Za-z0-9_\-]+"(,[a-z_]+="[A-Za-z0-9_\-]+")*\})? -?\d+(\.\d+)?)$/', $line);
        }
    }
}
