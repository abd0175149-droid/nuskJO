<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\Backup\BackupFailed;
use App\Services\Backup\SnapshotService;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Console\Command;

/**
 *   php artisan backup:run --reason=manual --label=pre-E03
 *
 * يُشغَّل داخل الحاوية باسم www-data (docker exec -u www-data ...).
 */
class BackupRunCommand extends Command
{
    protected $signature = 'backup:run
        {--reason=manual : scheduled|pre-migrate|pre-reset|manual|pre-restore|drill}
        {--label= : وسم اختياري يُضاف لاسم النسخة}
        {--allow-shrink : السماح بانكماش جدول أساسي أكثر من الحد}
        {--json : ملخص JSON بلا بيانات شخصية}';

    protected $description = 'نسخة احتياطية متسقة لقاعدة SQLite وملفات storage/app/public مع فحص سلامتها';

    public function handle(SnapshotService $snapshots): int
    {
        $reason = (string) $this->option('reason');

        if (!in_array($reason, BackupRun::REASONS, true)) {
            $this->error('سبب غير معروف. القيم: '.implode('|', BackupRun::REASONS));

            return self::FAILURE;
        }

        try {
            $result = $snapshots->run($reason, $this->option('label') ?: null, (bool) $this->option('allow-shrink'));
        } catch (BackupFailed $e) {
            $this->error('فشل النسخ: '.$e->getMessage());
            WhatsAppInbox::notifyAdmins('⚠️ فشل النسخ الاحتياطي', $e->getMessage(), '/settings/backups');

            return self::FAILURE;
        }

        $m = $result['manifest'];
        if (!empty($m['media_skipped'])) {
            WhatsAppInbox::notifyAdmins('⚠️ النسخ الاحتياطي بلا وسائط', 'تجاوزت ملفات الوسائط سقف النسخ؛ نُسخت القاعدة وحدها', '/settings/backups');
        }
        if ($this->option('json')) {
            $this->line(json_encode([
                'name' => $result['name'],
                'integrity' => $m['integrity'],
                'row_counts' => $m['row_counts'],
                'migrations' => $m['migrations'],
                'files' => $m['files'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("✅ {$result['name']} (integrity={$m['integrity']}, migrations={$m['migrations']['count']})");
        }

        return self::SUCCESS;
    }
}
