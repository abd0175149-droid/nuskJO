<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\WaBotSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الوضع الآمن بعد الاستعادة، يشغّله restore.sh قبل إقلاع التطبيق على القاعدة المستعادة:
 * البوت مطفأ، والإرسال مقفل، والطوابير والكاش والجلسات القديمة فارغة، ومراجعة معلّقة.
 * القفل لا يرفعه هذا الأمر ولا السكربت؛ المدير يرفعه بعد اعتماد المراجعة من صفحة النسخ الاحتياطي.
 *
 *   php artisan backup:safe-mode --restored-from=20261014T023000Z-scheduled --pre-restore=20261015T081500Z
 */
class BackupSafeMode extends Command
{
    public const REVIEW_KEY = 'restore_review_pending';

    /** بنود المراجعة قبل رفع القفل: ما تغيّر بعد وقت النسخة ولا تعيده الاستعادة */
    public const CHECKLIST = [
        'admin_numbers' => 'مراجعة أرقام الإدارة في المساعد الإداري',
        'users' => 'مراجعة المستخدمين والأدوار وكلمات المرور ومفاتيح الدخول المسحوبة بعد النسخة',
        'forget_requests' => 'إعادة تطبيق طلبات حذف بيانات العملاء المنفّذة بعد النسخة',
        'diff_report' => 'إعادة إدخال الفواتير والسندات من تقرير الفرق',
    ];

    /** وقت النسخة المستعادة (من اسمها) إن كانت مراجعة ما بعد الاستعادة معلّقة، لاستعماله كـ --since. */
    public static function pendingSince(): ?string
    {
        $pending = json_decode((string) Setting::get(self::REVIEW_KEY), true);
        if (!is_array($pending) || !preg_match('/^(\d{8}T\d{6}Z)/', (string) ($pending['snapshot'] ?? ''), $m)) {
            return null;
        }

        return Carbon::createFromFormat('Ymd\THis\Z', $m[1], 'UTC')->toIso8601String();
    }

    private const VOLATILE_TABLES = ['jobs', 'job_batches', 'cache', 'cache_locks', 'sessions'];

    protected $signature = 'backup:safe-mode
        {--restored-from= : اسم النسخة المستعادة}
        {--pre-restore= : طابع لقطة ما قبل الاستعادة}';

    protected $description = 'وضع آمن على قاعدة مستعادة: إطفاء البوت وقفل الإرسال وتفريغ الطوابير والجلسات';

    public function handle(): int
    {
        $snap = (string) $this->option('restored-from');
        $pre = (string) $this->option('pre-restore');

        if (!preg_match('/^\d{8}T\d{6}Z(-[A-Za-z0-9_.-]{1,48})?$/', $snap) || !preg_match('/^\d{8}T\d{6}Z$/', $pre)) {
            $this->error('--restored-from و--pre-restore مطلوبان بصيغة الطابع الزمني');

            return self::FAILURE;
        }

        if (Schema::hasTable('wa_bot_settings')) {
            WaBotSetting::current()->update(['enabled' => false, 'wa_suspended' => true]);
            $this->line('• البوت مطفأ والإرسال مقفل');
        } else {
            $this->warn('• جدول إعدادات البوت غير موجود في هذه النسخة');
        }

        foreach (self::VOLATILE_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $n = DB::table($table)->delete();
                $this->line("• {$table}: حُذف {$n}");
            }
        }

        Setting::set(self::REVIEW_KEY, json_encode([
            'snapshot' => $snap,
            'pre_restore' => $pre,
            'restored_at' => now()->toIso8601String(),
            'checklist' => self::CHECKLIST,
        ], JSON_UNESCAPED_UNICODE));

        $this->info('✅ الوضع الآمن مفعّل. القفل يُرفع من صفحة النسخ الاحتياطي بعد المراجعة.');

        return self::SUCCESS;
    }
}
