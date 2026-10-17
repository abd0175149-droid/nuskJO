<?php

namespace App\Services\Ops;

use App\Models\WaBotSetting;
use App\Services\Backup\BackupStatus;
use App\Services\WhatsApp\ChannelDoctor;
use App\Services\WhatsApp\ChannelHealth;
use App\Services\WhatsApp\GraphVersions;
use App\Services\WhatsApp\MetaHealth;
use App\Services\WhatsApp\Transport\WhatsAppTransport;
use App\Services\WhatsApp\WebhookStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * صفحة «حالة النظام» (E23، /ops/status) للقراءة فقط: لقطة واحدة مخزّنة 60 ثانية، بأقسام لكل منها مستوى
 * ok|warn|error|unknown. استعلامات محدودة ومجمّعة بلا تحميل جداول كاملة، ولا قيمة سرية ولا رقم عميل ولا نص رسالة.
 * لا docker.sock ولا أوامر على المضيف: ما تراه الحاوية وحدها.
 */
final class SystemStatus
{
    public const CACHE_KEY = 'ops:status:snapshot';

    public const TTL = 60;

    private const RANK = ['unknown' => 0, 'ok' => 1, 'warn' => 2, 'error' => 3];

    public static function snapshot(bool $fresh = false): array
    {
        $store = OpsHealth::store();
        if ($fresh) {
            $store->forget(self::CACHE_KEY);
        }

        return $store->remember(self::CACHE_KEY, self::TTL, fn () => self::build());
    }

    private static function build(): array
    {
        $sections = [];
        foreach (['app', 'database', 'disk', 'queue', 'beats', 'backups', 'doctor', 'whatsapp', 'numbers', 'reverb'] as $name) {
            try {
                $sections[$name] = self::{$name}();
            } catch (\Throwable $e) {
                // قسم يتعطل لا يُسقط الصفحة: اسم الصنف وحده (النص قد يحمل مساراً أو رابطاً)
                $sections[$name] = ['level' => 'unknown', 'error' => class_basename($e)];
            }
        }

        return ['at' => now()->toIso8601String(), 'sections' => $sections];
    }

    private static function app(): array
    {
        $prod = app()->isProduction();
        $debug = (bool) config('app.debug');

        return [
            'level' => $prod && $debug ? 'error' : 'ok',
            'env' => (string) app()->environment(),
            'debug' => $debug,
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            // ختم النشر من E24 إن وُجد
            'build' => config('app.build') ?: null,
            'timezone' => (string) config('app.timezone'),
        ];
    }

    private static function database(): array
    {
        $start = hrtime(true);
        DB::select('select 1');
        $ms = (int) round((hrtime(true) - $start) / 1e6);
        $out = ['level' => $ms > 500 ? 'warn' : 'ok', 'driver' => (string) config('database.default'), 'latency_ms' => $ms];
        if (config('database.default') === 'sqlite') {
            $file = (string) config('database.connections.sqlite.database');
            $out['size_mb'] = is_file($file) ? round(filesize($file) / 1048576, 1) : null;
            $out['sqlite'] = DB::selectOne('select sqlite_version() as v')->v ?? null;
            $out['journal_mode'] = (string) (DB::selectOne('PRAGMA journal_mode')->journal_mode ?? '');
            $out['busy_timeout'] = (int) (array_values((array) DB::selectOne('PRAGMA busy_timeout'))[0] ?? 0);
        }

        return $out;
    }

    private static function disk(): array
    {
        $paths = [];
        $level = 'ok';
        foreach (['storage' => storage_path(), 'database' => database_path()] as $key => $path) {
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if (!$total || $free === false) {
                $paths[$key] = null;

                continue;
            }
            $freePct = (int) floor($free * 100 / $total);
            $paths[$key] = ['used_pct' => 100 - $freePct, 'free_gb' => round($free / 1073741824, 1)];
            $level = self::worst($level, $freePct < 5 ? 'error' : ($freePct < 10 ? 'warn' : 'ok'));
        }

        return ['level' => $level] + $paths;
    }

    private static function queue(): array
    {
        $now = now()->getTimestamp();
        $retry = (int) (config('queue.connections.'.config('queue.default').'.retry_after') ?? 240);
        $queues = array_fill_keys([...OpsHealth::queues(), 'wa-media', 'wa-media-out'], null);
        $rows = DB::table('jobs')
            ->selectRaw('queue, sum(case when reserved_at is null then 1 else 0 end) as available, min(case when reserved_at is null then available_at end) as oldest,
                sum(case when reserved_at is not null and reserved_at < ? then 1 else 0 end) as stuck', [$now - $retry])
            ->groupBy('queue')->get();
        $level = 'ok';
        foreach ($queues as $name => $_) {
            $queues[$name] = ['available' => 0, 'oldest_seconds' => null, 'stuck' => 0];
        }
        foreach ($rows as $r) {
            $oldest = $r->oldest !== null ? max(0, $now - (int) $r->oldest) : null;
            $queues[$r->queue] = ['available' => (int) $r->available, 'oldest_seconds' => $oldest, 'stuck' => (int) $r->stuck];
            if ((int) $r->stuck > 0 || ($oldest !== null && $oldest > (int) config('ops.backlog_stale_seconds', 300))) {
                $level = 'warn';
            }
        }
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        return ['level' => $failed > 0 ? self::worst($level, 'warn') : $level, 'connection' => (string) config('queue.default'),
            'retry_after' => $retry, 'queues' => $queues, 'failed_24h' => $failed];
    }

    private static function beats(): array
    {
        $s = app(OpsHealth::class)->snapshot();

        return [
            'level' => $s['codes'] === [] ? 'ok' : (in_array('db', $s['codes'], true) || in_array('schedule_stale', $s['codes'], true) ? 'error' : 'warn'),
            'codes' => $s['codes'],
            'schedule_age' => $s['schedule_age'],
            'queues' => $s['queues'],
            'warnings' => $s['warnings'],
        ];
    }

    private static function backups(): array
    {
        $s = app(BackupStatus::class)->summary();
        if (!$s['enabled']) {
            return ['level' => 'unknown', 'enabled' => false];
        }
        $states = array_map(fn ($i) => $i['state'] ?? 'warn', array_intersect_key($s['items'], array_flip(['snapshot', 'collected', 'pulled', 'drill'])));
        $level = 'ok';
        foreach ($states as $state) {
            $level = self::worst($level, $state === 'bad' ? 'error' : ($state === 'warn' ? 'warn' : 'ok'));
        }
        $items = [];
        foreach (['snapshot', 'collected', 'pulled', 'drill'] as $k) {
            $items[$k] = ['at' => $s['items'][$k]['at'] ?? null, 'age_hours' => $s['items'][$k]['age_hours'] ?? null, 'state' => $s['items'][$k]['state'] ?? null];
        }

        return ['level' => $level, 'enabled' => true, 'items' => $items];
    }

    private static function doctor(): array
    {
        $last = ChannelDoctor::last();
        if ($last === null) {
            return ['level' => 'unknown', 'at' => null];
        }
        $problems = array_values(array_map(fn ($c) => ['level' => $c['level'], 'code' => $c['code'], 'message' => $c['message']],
            array_filter($last['checks'], fn ($c) => in_array($c['level'], ['warn', 'error'], true))));

        return [
            'level' => $last['counts']['error'] > 0 ? 'error' : ($last['counts']['warn'] > 0 ? 'warn' : 'ok'),
            'at' => $last['at'],
            'counts' => $last['counts'],
            'problems' => array_slice($problems, 0, 20),
        ];
    }

    private static function whatsapp(): array
    {
        $s = WaBotSetting::current();
        $channel = (array) ($s->wa_channel_health ?? []);
        $credentials = (array) ($s->wa_credentials_status ?? []);
        $level = 'ok';

        $health = null;
        if (ChannelHealth::available()) {
            $state = ChannelHealth::current($s);
            $health = ['state' => $state->value, 'label' => $state->label(), 'until' => $s->wa_health_until?->toIso8601String(),
                'checked_at' => $s->wa_health_checked_at?->toIso8601String(), 'machine' => ChannelHealth::enabled()];
            $level = self::worst($level, match ($state->value) {
                'BLOCKED', 'TOKEN_INVALID' => 'error',
                'LIMITED' => 'warn',
                default => 'ok',
            });
        }
        $level = self::worst($level, match ($channel['state'] ?? null) {
            'critical' => 'error',
            'warn', 'unreachable' => 'warn',
            default => 'ok',
        });

        $webhook = is_array($channel['webhook'] ?? null) ? $channel['webhook'] : null;
        if (($webhook['ok'] ?? null) === false) {
            $level = self::worst($level, 'error');
        }
        $level = self::worst($level, match ($credentials['state'] ?? null) {
            'critical' => 'error',
            'warn', 'unreachable' => 'warn',
            default => 'ok',
        });

        $graph = GraphVersions::panel();
        $level = self::worst($level, match ($graph['requests']['level']) {
            'expired', 'unknown' => 'error',
            'warn' => 'warn',
            default => 'ok',
        });

        $intake = WebhookStats::summary();
        if (($intake['dead_open'] ?? 0) > 0) {
            $level = self::worst($level, 'warn');
        }

        return [
            'level' => $level,
            'configured' => $s->channelReady(),
            'monitor' => MetaHealth::monitorOn(),
            'health' => $health,
            'channel' => [
                'state' => $channel['state'] ?? null,
                'can_send_message' => $channel['can_send_message'] ?? null,
                'quality_rating' => $channel['quality_rating'] ?? null,
                'number' => $channel['number'] ?? null,
                'checked_at' => $channel['checked_at'] ?? null,
            ],
            'webhook' => $webhook === null ? null : [
                'ok' => $webhook['ok'],
                'issues' => $webhook['issues'] ?? [],
                'callback' => $webhook['callback'] ?? null,
                'overrides' => $webhook['overrides'] ?? [],
                'missing_fields' => $webhook['missing_fields'] ?? null,
                'field_versions' => $webhook['field_versions'] ?? [],
                'configuration_available' => $webhook['configuration_available'] ?? null,
                'subscriptions_at' => $webhook['subscriptions']['at'] ?? null,
            ],
            'intake' => [
                'last24' => $intake['last24'] ?? [],
                'dead_open' => $intake['dead_open'] ?? 0,
                'signatures' => $intake['signatures'] ?? [],
                'fence' => ['mode' => $intake['fence']['mode'] ?? null, 'counts' => $intake['fence']['counts'] ?? []],
                'last_signed_at' => Cache::get('wa:sig:last_ok_at'),
            ],
            'graph' => ['requests' => $graph['requests'], 'webhook' => $graph['webhook'], 'appsecret_proof' => $graph['appsecret_proof']],
            'credentials' => array_intersect_key($credentials, array_flip(['state', 'reasons', 'expires_at', 'never_expires', 'days_left', 'missing_scopes', 'checked_at'])),
            'transport' => app(WhatsAppTransport::class)->name(),
        ];
    }

    /** أرقام الحساب (C054): من الكاش ساعة، أو قراءة واحدة إن فرغ (فحوص ميتا مفعّلة والقناة مهيّأة) */
    private static function numbers(): array
    {
        $s = WaBotSetting::current();
        if (!config('ops.meta_checks') || !$s->channelReady()) {
            return ['level' => 'unknown', 'state' => 'not_configured', 'numbers' => []];
        }
        $r = MetaHealth::phoneNumbers(10);
        $numbers = $r['numbers'] ?? [];
        $level = match ($r['state']) {
            'ok' => 'ok',
            'no_waba', 'not_configured' => 'unknown',
            default => 'warn',
        };
        foreach ($numbers as $n) {
            if ($n['ours'] && $n['status'] !== null && $n['status'] !== 'CONNECTED') {
                $level = 'error';
            } elseif ($n['status'] !== 'CONNECTED' || $n['quality_rating'] === 'RED') {
                $level = self::worst($level, 'warn');
            }
        }

        return ['level' => $level, 'state' => $r['state'], 'at' => $r['at'] ?? null, 'numbers' => $numbers];
    }

    private static function reverb(): array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return ['level' => 'unknown', 'enabled' => false];
        }
        $port = (int) config('reverb.servers.reverb.port', 8080);
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1.0);
        if (is_resource($sock)) {
            fclose($sock);
        }

        return ['level' => $sock ? 'ok' : 'warn', 'enabled' => true, 'port' => $port, 'listening' => (bool) $sock];
    }

    private static function worst(string $a, string $b): string
    {
        return self::RANK[$b] > self::RANK[$a] ? $b : $a;
    }
}
