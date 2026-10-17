<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupStatus;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * مراقب النسخ (ساعي): ينبّه المدراء إن تأخرت اللقطة أو التجميع أو السحب أو التمرين، أو امتلأ القرص.
 * تنبيه واحد يومياً لكل نوع، عبر الجرس وFCM لا واتساب.
 */
class BackupWatchdog extends Command
{
    protected $signature = 'backup:watchdog';

    protected $description = 'تنبيه المدراء عند تأخر النسخ الاحتياطي أو امتلاء القرص';

    public function handle(BackupStatus $status): int
    {
        $sent = 0;
        foreach ($status->alerts() as $type => $message) {
            if (Cache::add("backup:watchdog:{$type}:".now()->format('Ymd'), true, now()->addDay())) {
                WhatsAppInbox::notifyAdmins('⚠️ النسخ الاحتياطي', $message, '/settings/backups', $type === 'disk' ? 'RB-08' : 'RB-06');
                $sent++;
            }
        }

        $this->info("تنبيهات: {$sent}");

        return self::SUCCESS;
    }
}
