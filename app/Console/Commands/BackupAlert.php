<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * تنبيه المدراء من سكربتات المضيف (collect.sh وdrill.sh) عند فشل خطوة أو امتلاء القرص.
 * الرسالة نفسها لا تتكرر قبل مرور ساعات العزل، فـ cron لا يغرق الإشعارات.
 *
 *   php artisan backup:alert "collect.sh: فشل التشفير"
 */
class BackupAlert extends Command
{
    protected $signature = 'backup:alert
        {message : سبب قصير بلا بيانات شخصية}
        {--hours=6 : لا تُكرَّر الرسالة نفسها قبل هذه المدة}';

    protected $description = 'تنبيه المدراء بخلل في النسخ الاحتياطي مع منع التكرار';

    public function handle(): int
    {
        $message = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $this->argument('message'))), 0, 300);
        if ($message === '') {
            $this->error('الرسالة فارغة');

            return self::FAILURE;
        }

        $hours = max(1, (int) $this->option('hours'));
        if (!Cache::add('backup:alert:'.sha1($message), true, now()->addHours($hours))) {
            $this->line('مكرر خلال المهلة؛ لم يُرسل');

            return self::SUCCESS;
        }

        WhatsAppInbox::notifyAdmins('⚠️ النسخ الاحتياطي', $message, '/settings/backups');
        $this->info('أُرسل التنبيه');

        return self::SUCCESS;
    }
}
