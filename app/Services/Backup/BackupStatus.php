<?php

namespace App\Services\Backup;

use App\Console\Commands\BackupSafeMode;
use App\Models\BackupRun;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * حالة النسخ الاحتياطي لصفحة النسخ والمراقب: لكل بند وقت آخر حدوث وحالة ok|warn|bad.
 * لا مسارات ولا بيانات عملاء.
 */
class BackupStatus
{
    /** البند => [ساعات ok، ساعات warn، عنوان التنبيه] */
    public const THRESHOLDS = [
        'snapshot' => [26, 48, 'لا نسخة احتياطية ناجحة منذ أكثر من يوم'],
        'collected' => [26, 72, 'لم تُسحب النسخ مشفّرة إلى المضيف منذ أكثر من يوم'],
        'pulled' => [8 * 24, 14 * 24, 'لم تُنقل نسخة إلى جهاز المالك منذ أكثر من 8 أيام'],
        'drill' => [35 * 24, 60 * 24, 'لم يُجرَ تمرين استعادة منذ أكثر من 35 يوماً'],
    ];

    public function __construct(private DiskUsage $disk, private SnapshotService $snapshots) {}

    public function summary(): array
    {
        $hasRuns = Schema::hasTable('backup_runs');

        $lastOk = $hasRuns ? BackupRun::where('status', 'ok')->latest('finished_at')->first() : null;
        $spoolAt = $this->snapshots->latestManifest()['created_at'] ?? null;
        $snapshotAt = $this->latest([$lastOk?->finished_at, $spoolAt ? Carbon::parse($spoolAt) : null]);

        $lastDrill = $hasRuns ? BackupRun::whereNotNull('drill_summary')->latest('updated_at')->first() : null;

        $items = [
            'snapshot' => $this->item('snapshot', $snapshotAt),
            'collected' => $this->item('collected', $hasRuns ? $this->carbon(BackupRun::max('collected_at')) : null),
            'pulled' => $this->item('pulled', $hasRuns ? $this->carbon(BackupRun::max('pulled_at')) : null),
            'drill' => $this->item('drill', $lastDrill?->updated_at) + [
                'result' => $lastDrill?->drill_summary['result'] ?? null,
                'seconds' => $lastDrill?->drill_summary['drill']['seconds'] ?? null,
            ],
        ];

        $items['snapshot'] += [
            'db_bytes' => $lastOk?->db_bytes,
            'media_bytes' => $lastOk?->media_bytes,
            'integrity' => $lastOk?->integrity,
        ];

        $used = $this->disk->usedPercent(storage_path());
        $spoolMb = (int) round($this->disk->directoryBytes((string) config('backup.spool_path')) / 1048576);
        $warnPct = (int) config('backup.disk_warn_pct', 90);
        $items['disk'] = [
            'used_pct' => $used,
            'spool_mb' => $spoolMb,
            'state' => $used === null ? 'warn'
                : ($used >= 95 ? 'bad' : (($used >= $warnPct || $spoolMb > (int) config('backup.spool_max_mb', 500)) ? 'warn' : 'ok')),
        ];

        return [
            'enabled' => (bool) config('backup.enabled'),
            'items' => $items,
            'review' => $this->review(),
        ];
    }

    /** البنود التي تستدعي تنبيهاً، مع نص التنبيه. */
    public function alerts(): array
    {
        $summary = $this->summary();
        $alerts = [];

        foreach (self::THRESHOLDS as $key => [, , $title]) {
            if ($summary['items'][$key]['state'] !== 'ok') {
                $alerts[$key] = $title;
            }
        }

        $disk = $summary['items']['disk'];
        if ($disk['state'] !== 'ok') {
            $alerts['disk'] = "القرص مستخدم بنسبة {$disk['used_pct']}% ومجلد النسخ {$disk['spool_mb']}MB";
        }

        return $alerts;
    }

    public function review(): ?array
    {
        $pending = json_decode((string) Setting::get(BackupSafeMode::REVIEW_KEY), true);

        return is_array($pending) ? $pending : null;
    }

    private function item(string $key, ?Carbon $at): array
    {
        [$ok, $warn] = self::THRESHOLDS[$key];
        $hours = $at ? $at->diffInHours(now(), true) : null;

        return [
            'at' => $at?->toIso8601String(),
            'age_hours' => $hours === null ? null : (int) floor($hours),
            'state' => $hours === null ? 'bad' : ($hours <= $ok ? 'ok' : ($hours <= $warn ? 'warn' : 'bad')),
        ];
    }

    private function latest(array $dates): ?Carbon
    {
        $dates = array_filter($dates);

        return $dates ? max($dates) : null;
    }

    private function carbon(mixed $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}
