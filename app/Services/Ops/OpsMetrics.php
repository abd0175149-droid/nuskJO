<?php

namespace App\Services\Ops;

use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BlockService;
use App\Services\WhatsApp\ChannelHealth;
use App\Services\WhatsApp\MetaErrorCatalog;
use App\Services\WhatsApp\OutboundCounter;
use App\Services\WhatsApp\WebhookStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * لقطة المقاييس التشغيلية (E24): أعداد مجمّعة محدودة بالزمن على فهارس E18 وE24، بلا تحميل صفوف ولا نماذج،
 * ولا هاتف ولا اسم ولا نص. يأخذها whatsapp:ops-check كل 5 دقائق ويحفظها في مخزن ops (file، لا SQLite)،
 * فيقرؤها /internal/metrics وصفحة الإخفاقات دون أي استعلام. كل جزء في try/catch: عطل واحد لا يُسقط اللقطة.
 */
final class OpsMetrics
{
    public const CACHE_KEY = 'ops:metrics:last';

    public const HOURLY_KEY = 'ops:metrics:hourly:';

    /** طوابير الوسائط (E14 وE32) لها عاملها، وتُعرض مع طابوري default والردود */
    private const MEDIA_QUEUES = ['wa-media-out', 'wa-media'];

    /** فئات MetaErrorCatalog كلها بترتيب ثابت (الفارغ يُعدّ unknown) */
    public static function categories(): array
    {
        return array_keys(MetaErrorCatalog::CATEGORIES);
    }

    /** @return list<string> */
    public static function queues(): array
    {
        return array_values(array_unique([...OpsHealth::queues(), ...self::MEDIA_QUEUES]));
    }

    /** آخر لقطة محفوظة (null قبل أول تشغيل أو بعد 15 دقيقة بلا مجدول) */
    public static function last(): ?array
    {
        try {
            $snap = OpsHealth::store()->get(self::CACHE_KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_array($snap) ? $snap : null;
    }

    public function snapshot(): array
    {
        $errors = [];
        $part = function (string $name, callable $fn, mixed $fallback = null) use (&$errors) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $errors[] = $name;
                report($e);

                return $fallback;
            }
        };

        $outbound = $part('outbound_today', fn () => OutboundCounter::today(true), ['by_source' => [], 'total' => 0, 'test' => 0]);

        return [
            'at' => now()->getTimestamp(),
            'queue' => $part('queue', fn () => $this->queue(), []),
            'failed_jobs' => $part('failed_jobs', fn () => $this->failedJobs(), ['1h' => null, '24h' => null]),
            'outbound_failed_1h' => $part('outbound_failed', fn () => $this->outboundFailed(), array_fill_keys(self::categories(), 0)),
            'unknown_sends_1h' => $part('unknown_sends', fn () => WaMessage::where('direction', 'out')
                ->whereIn('status', [WaMessage::STATUS_UNKNOWN, 'unconfirmed'])->where('created_at', '>=', now()->subHour())->count()),
            'webhook_failed_24h' => $part('webhook_failed', function () {
                $last24 = WebhookStats::summary(true)['last24'] ?? [];

                return (int) ($last24['failed'] ?? 0) + (int) ($last24['dead'] ?? 0);
            }),
            'waiting_human' => $part('waiting_human', fn () => $this->waitingHuman()),
            'bot_latency_p90_ms_1h' => $part('bot_latency', fn () => $this->latencyP90()),
            'outbound_today' => $outbound,
            'automated_today' => $part('automated_today', fn () => OutboundCounter::automatedToday(), []),
            'service_billable_month' => $part('service_billable', fn () => OutboundCounter::serviceBillableMonth()),
            'channel_health' => $part('channel_health', fn () => ChannelHealth::available() ? ChannelHealth::current()->value : null),
            'channel_health_enabled' => $part('channel_health', fn () => ChannelHealth::enabled(), false),
            'scheduler_beat_age_s' => $part('scheduler', function () {
                $beat = Cache::get(OpsHealth::SCHEDULE_BEAT);

                return $beat ? max(0, now()->getTimestamp() - (int) $beat) : null;
            }),
            'errors' => $errors,
        ];
    }

    /**
     * لكل طابور: المتاح الآن، وعمر أقدمه منذ صار متاحاً، والمحجوز بعد retry_after (عامل مات وهو يعمل). استعلام واحد.
     *
     * @return array<string, array{jobs: int, oldest_age_s: ?int, reserved_stuck: int}>
     */
    private function queue(): array
    {
        $now = now()->getTimestamp();
        $retryAfter = (int) config('queue.connections.database.retry_after', 240);
        $out = array_fill_keys(self::queues(), ['jobs' => 0, 'oldest_age_s' => null, 'reserved_stuck' => 0]);

        $rows = DB::table('jobs')->whereIn('queue', array_keys($out))->groupBy('queue')
            ->selectRaw('queue,
                SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) as available,
                MIN(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN available_at END) as oldest,
                SUM(CASE WHEN reserved_at IS NOT NULL AND reserved_at < ? THEN 1 ELSE 0 END) as stuck', [$now, $now, $now - $retryAfter])
            ->get();
        foreach ($rows as $r) {
            $out[$r->queue] = [
                'jobs' => (int) $r->available,
                'oldest_age_s' => $r->oldest !== null ? max(0, $now - (int) $r->oldest) : null,
                'reserved_stuck' => (int) $r->stuck,
            ];
        }

        return $out;
    }

    /** @return array{1h: int, 24h: int} */
    private function failedJobs(): array
    {
        $r = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())
            ->selectRaw('COUNT(*) as day, SUM(CASE WHEN failed_at >= ? THEN 1 ELSE 0 END) as hour', [now()->subHour()->format('Y-m-d H:i:s')])
            ->first();

        return ['1h' => (int) ($r->hour ?? 0), '24h' => (int) ($r->day ?? 0)];
    }

    /** الصادر الفاشل في الساعة بفئة E18 (العمود error_category، والفارغ unknown) على فهرس (direction, status, created_at) */
    private function outboundFailed(): array
    {
        $out = array_fill_keys(self::categories(), 0);
        $rows = WaMessage::where('direction', 'out')->where('status', WaMessage::STATUS_FAILED)->where('created_at', '>=', now()->subHour())
            ->groupBy('error_category')->selectRaw('error_category, COUNT(*) as n')->pluck('n', 'error_category');
        foreach ($rows as $category => $n) {
            $key = isset($out[$category]) ? $category : 'unknown';
            $out[$key] += (int) $n;
        }

        return $out;
    }

    /** محوّلة لموظف ونافذتها مفتوحة وآخر وارد أقدم من 30 دقيقة (أرقام مجمّعة بلا نطاق مستخدم) */
    private function waitingHuman(): int
    {
        return WaConversation::where('needs_attention', true)
            ->where('last_inbound_at', '>', now()->subDay())->where('last_inbound_at', '<=', now()->subMinutes(30))
            ->when(BlockService::available(), fn ($q) => $q->whereNull('blocked_at'))
            ->count();
    }

    /** p90 لزمن رد البوت في الساعة: القيم مرتبة في SQL، ثم limit 1 offset floor(0.9×n) */
    private function latencyP90(): ?int
    {
        $base = WaBotUsage::where('source', 'live')->where('created_at', '>=', now()->subHour())->where('latency_ms', '>', 0);
        $n = (clone $base)->count();
        if ($n === 0) {
            return null;
        }

        return (int) (clone $base)->orderBy('latency_ms')->offset(min($n - 1, (int) floor($n * 0.9)))->limit(1)->value('latency_ms');
    }

    /** يحفظ اللقطة (15 دقيقة) وملخصاً لكل ساعة (48 ساعة) لرسوم بسيطة */
    public static function remember(array $snap): void
    {
        $store = OpsHealth::store();
        $store->put(self::CACHE_KEY, $snap, now()->addMinutes((int) config('ops.metrics_ttl_minutes', 15)));
        $store->put(self::HOURLY_KEY.now()->format('YmdH'), [
            'at' => $snap['at'],
            'queue_oldest_s' => self::oldestQueueAge($snap),
            'failed_jobs_1h' => $snap['failed_jobs']['1h'] ?? null,
            'outbound_today' => $snap['outbound_today']['total'] ?? null,
            'unknown_sends_1h' => $snap['unknown_sends_1h'] ?? null,
            'waiting_human' => $snap['waiting_human'] ?? null,
        ], now()->addHours(48));
    }

    public static function oldestQueueAge(array $snap): ?int
    {
        $ages = array_filter(array_map(fn ($q) => $q['oldest_age_s'] ?? null, (array) ($snap['queue'] ?? [])), fn ($v) => $v !== null);

        return $ages === [] ? null : (int) max($ages);
    }
}
