<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\Backup\BackupFailed;
use App\Services\Backup\SnapshotService;
use Illuminate\Console\Command;

/**
 * يكتب manifest.json لمجلد فيه database.sqlite نُسخ بغير backup:run (لقطة ما قبل الاستعادة في restore.sh)،
 * فيصبح لقطة كاملة تُفحص بـ backup:verify ويُرجع إليها بـ restore.sh --dir.
 *
 *   php artisan backup:manifest /out --reason=pre-restore
 */
class BackupManifest extends Command
{
    protected $signature = 'backup:manifest
        {dir : مجلد فيه database.sqlite}
        {--reason=pre-restore}
        {--label=}';

    protected $description = 'كتابة manifest.json لنسخة قاعدة أُخذت يدوياً';

    public function handle(SnapshotService $snapshots): int
    {
        $dir = rtrim((string) $this->argument('dir'), '/');
        $reason = (string) $this->option('reason');

        if (!in_array($reason, BackupRun::REASONS, true) || !is_file("{$dir}/database.sqlite")) {
            $this->error('مجلد بلا database.sqlite أو سبب غير معروف');

            return self::FAILURE;
        }

        try {
            $manifest = $snapshots->describe($dir, $reason, $this->option('label') ?: null);
        } catch (BackupFailed|\PDOException $e) {
            $this->error('تعذّرت قراءة القاعدة: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("✅ manifest.json (integrity={$manifest['integrity']}, migrations={$manifest['migrations']['count']})");

        return $manifest['integrity'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
