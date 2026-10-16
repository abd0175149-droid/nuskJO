<?php

namespace App\Http\Controllers;

use App\Services\Ops\OpsMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /internal/metrics بصيغة Prometheus النصية 0.0.4 (E24): مغلق افتراضياً. 404 ما لم يُضبط OPS_METRICS_TOKEN، و404
 * لرمز خاطئ أيضاً (لا 401: لا يكشف وجود المسار)، بمقارنة hash_equals وحد 30 في الدقيقة. يقرأ آخر لقطة من
 * whatsapp:ops-check فقط، بلا أي استعلام ولا جلسة (routes/internal.php خارج مجموعة web).
 * قيم التسميات من قوائم ثابتة في الكود (الطوابير والفئات والمصادر والحالات): لا هاتف ولا اسم ولا معرّف محادثة.
 */
class InternalMetricsController extends Controller
{
    /** المصادر المعروضة بأسمائها؛ أي مصدر آخر في الإجمالي وحده */
    public const SOURCES = ['bot', 'staff', 'system', 'rule', 'test'];

    public const HEALTH_STATES = ['AVAILABLE', 'LIMITED', 'BLOCKED', 'TOKEN_INVALID', 'UNKNOWN'];

    public function __invoke(Request $request): Response
    {
        $token = (string) config('ops.metrics_token');
        if ($token === '' || !hash_equals($token, (string) $request->bearerToken())) {
            abort(404);
        }
        $snap = OpsMetrics::last();
        if ($snap === null) {
            return response("no snapshot\n", 503, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response(self::render($snap), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /** المخرج من اللقطة وحدها: كل تسمية تمر بقائمة ثابتة، وما خرج عنها يُسقط */
    public static function render(array $snap): string
    {
        $lines = [];
        $metric = function (string $name, string $help, string $type, array $samples) use (&$lines) {
            $lines[] = "# HELP {$name} {$help}";
            $lines[] = "# TYPE {$name} {$type}";
            foreach ($samples as [$labels, $value]) {
                if ($value === null) {
                    continue;
                }
                $l = $labels === [] ? '' : '{'.implode(',', array_map(fn ($k, $v) => $k.'="'.$v.'"', array_keys($labels), $labels)).'}';
                $lines[] = $name.$l.' '.(is_float($value) ? $value : (int) $value);
            }
        };

        $queues = OpsMetrics::queues();
        $queueSamples = $oldest = [];
        foreach ($queues as $q) {
            $row = $snap['queue'][$q] ?? null;
            $queueSamples[] = [['queue' => $q], $row['jobs'] ?? 0];
            $oldest[] = [['queue' => $q], $row['oldest_age_s'] ?? 0];
        }
        $metric('nuskjo_queue_jobs', 'Jobs available and waiting per queue.', 'gauge', $queueSamples);
        $metric('nuskjo_queue_oldest_seconds', 'Age of the oldest waiting job per queue.', 'gauge', $oldest);
        $metric('nuskjo_failed_jobs', 'Failed jobs in the window.', 'gauge', [
            [['window' => '1h'], $snap['failed_jobs']['1h'] ?? null], [['window' => '24h'], $snap['failed_jobs']['24h'] ?? null],
        ]);

        $bySource = (array) ($snap['outbound_today']['by_source'] ?? []);
        $bySource['test'] = $snap['outbound_today']['test'] ?? 0;
        $metric('nuskjo_wa_outbound_today', 'Delivered outbound messages since midnight Amman, by source.', 'gauge',
            array_map(fn ($s) => [['source' => $s], (int) ($bySource[$s] ?? 0)], self::SOURCES));

        $failed = (array) ($snap['outbound_failed_1h'] ?? []);
        $metric('nuskjo_wa_outbound_failed_1h', 'Outbound messages failed in the last hour, by Meta error category.', 'gauge',
            array_map(fn ($c) => [['category' => $c], (int) ($failed[$c] ?? 0)], OpsMetrics::categories()));
        $metric('nuskjo_wa_unknown_sends_1h', 'Sends with an unknown outcome in the last hour.', 'gauge', [[[], $snap['unknown_sends_1h'] ?? null]]);
        $metric('nuskjo_wa_waiting_human', 'Handed-off conversations waiting more than 30 minutes.', 'gauge', [[[], $snap['waiting_human'] ?? null]]);

        $health = $snap['channel_health'] ?? null;
        if ($health !== null) {
            $metric('nuskjo_wa_channel_health', 'Channel health state (1 for the current state).', 'gauge',
                array_map(fn ($st) => [['state' => $st], $st === $health ? 1 : 0], self::HEALTH_STATES));
        }
        $metric('nuskjo_bot_latency_p90_ms', 'Bot reply latency p90 over the last hour.', 'gauge', [[[], $snap['bot_latency_p90_ms_1h'] ?? null]]);
        $metric('nuskjo_webhook_failed_24h', 'Webhook events failed or dead in the last 24 hours.', 'gauge', [[[], $snap['webhook_failed_24h'] ?? null]]);
        $metric('nuskjo_wa_service_billable_month', 'Billable service messages since the start of the month (Amman).', 'gauge', [[[], $snap['service_billable_month'] ?? null]]);
        $metric('nuskjo_scheduler_beat_age_seconds', 'Seconds since the scheduler heartbeat.', 'gauge', [[[], $snap['scheduler_beat_age_s'] ?? null]]);
        $metric('nuskjo_metrics_snapshot_timestamp_seconds', 'When ops-check took this snapshot.', 'gauge', [[[], $snap['at'] ?? null]]);

        return implode("\n", $lines)."\n";
    }
}
