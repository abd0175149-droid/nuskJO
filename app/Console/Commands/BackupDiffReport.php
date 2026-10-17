<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupFailed;
use App\Services\Backup\SnapshotDiff;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * بعد restore.sh: ما أُنشئ بعد وقت النسخة في لقطة ما قبل الاستعادة وغاب عن القاعدة الحية،
 * بأرقام المستندات والمعرّفات فقط، ليعيد الموظف إدخاله يدوياً.
 *
 *   php artisan backup:diff-report /tmp/pre-restore-<TS>.sqlite
 */
class BackupDiffReport extends Command
{
    protected $signature = 'backup:diff-report
        {snapshot : مسار database.sqlite للقطة ما قبل الاستعادة}
        {--since= : وقت النسخة المستعادة (افتراضياً من مراجعة ما بعد الاستعادة المعلّقة)}
        {--json}';

    protected $description = 'تقرير ما يجب إعادة إدخاله يدوياً بعد الاستعادة (أرقام مستندات فقط)';

    public function handle(SnapshotDiff $diff): int
    {
        $since = $this->option('since') ?: BackupSafeMode::pendingSince();
        if (!$since) {
            $this->error('حدّد --since (لا توجد مراجعة استعادة معلّقة يُؤخذ منها الوقت)');

            return self::FAILURE;
        }

        try {
            $report = $diff->report((string) $this->argument('snapshot'), $since);
        } catch (BackupFailed|\PDOException|QueryException $e) {
            $this->error('تعذّر التقرير: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['since' => $since, 'tables' => $report], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (!$report) {
            $this->info("✅ لا شيء بعد {$since} غائب عن القاعدة الحية (عدا رسائل واتساب: backup:replay-messages)");

            return self::SUCCESS;
        }

        $this->table(['الجدول', 'الغائب', 'الأرقام'], array_map(
            fn ($table, $r) => [$table, $r['missing'], implode('، ', $r['identifiers']).($r['missing'] > count($r['identifiers']) ? ' …' : '')],
            array_keys($report),
            $report
        ));

        return self::SUCCESS;
    }
}
