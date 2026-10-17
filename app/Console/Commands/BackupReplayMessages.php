<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupFailed;
use App\Services\Backup\MessageReplayer;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * بعد restore.sh: يعيد رسائل واتساب التي وصلت بعد وقت النسخة من لقطة ما قبل الاستعادة (لا ردّ ولا إرسال).
 *
 *   php artisan backup:replay-messages /tmp/pre-restore-<TS>.sqlite --dry-run
 *   php artisan backup:replay-messages /tmp/pre-restore-<TS>.sqlite
 */
class BackupReplayMessages extends Command
{
    protected $signature = 'backup:replay-messages
        {snapshot : مسار database.sqlite للقطة ما قبل الاستعادة}
        {--since= : وقت النسخة المستعادة (افتراضياً من مراجعة ما بعد الاستعادة المعلّقة)}
        {--dry-run : يحسب ما سيُدرج ثم يتراجع}';

    protected $description = 'استرجاع رسائل واتساب الفائتة بعد استعادة نسخة احتياطية';

    public function handle(MessageReplayer $replayer): int
    {
        $since = $this->option('since') ?: BackupSafeMode::pendingSince();
        if (!$since) {
            $this->error('حدّد --since (لا توجد مراجعة استعادة معلّقة يُؤخذ منها الوقت)');

            return self::FAILURE;
        }

        try {
            $stats = $replayer->replay((string) $this->argument('snapshot'), $since, (bool) $this->option('dry-run'));
        } catch (BackupFailed|\PDOException|QueryException $e) {
            $this->error('تعذّر الاسترجاع: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['البند', 'العدد'], [
            ['رسائل بعد '.$since, $stats['candidates']],
            [$this->option('dry-run') ? 'ستُدرج' : 'أُدرجت', $stats['inserted']],
            ['موجودة مسبقاً', $stats['duplicates']],
            ['محادثات جديدة', $stats['conversations_created']],
            ['محادثات تحدّث ملخصها', $stats['conversations_touched']],
        ]);
        if ($this->option('dry-run')) {
            $this->warn('تجربة فقط: لم يُكتب شيء.');
        }

        return self::SUCCESS;
    }
}
