<?php

namespace App\Jobs;

use App\Services\Backup\BackupFailed;
use App\Services\Backup\SnapshotService;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** «نسخة الآن» من صفحة النسخ الاحتياطي. */
class RunBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public ?int $userId = null) {}

    public function handle(SnapshotService $snapshots): void
    {
        try {
            $snapshots->run('manual', 'web', false, $this->userId);
        } catch (BackupFailed $e) {
            WhatsAppInbox::notifyAdmins('⚠️ فشل النسخ الاحتياطي', $e->getMessage(), '/settings/backups');
        }
    }
}
