<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * آخر نشاط للموظف (E27): يُنسخ كل 15 دقيقة باستعلام واحد من جدول الجلسات، الذي يكتبه Laravel مع كل طلب أصلاً.
 * لا وسيط ولا Cache::add لكل طلب (مخزن الكاش database يجعل حتى Cache::add كتابة على SQLite). القيمة لا ترجع للخلف،
 * وبتوقيت UTC كبقية الأعمدة.
 */
class UsersSyncLastSeen extends Command
{
    protected $signature = 'users:sync-last-seen';

    protected $description = 'نسخ آخر نشاط لكل مستخدم من جدول الجلسات (استعلام واحد، بلا كتابة لكل طلب)';

    public function handle(): int
    {
        if (!Schema::hasTable('sessions') || !Schema::hasColumn('users', 'last_seen_at')) {
            $this->line('لا جدول جلسات أو لا عمود last_seen_at بعد.');

            return self::SUCCESS;
        }

        $latest = DB::connection()->getDriverName() === 'sqlite'
            ? "(SELECT datetime(MAX(s.last_activity), 'unixepoch') FROM sessions s WHERE s.user_id = users.id)"
            : '(SELECT FROM_UNIXTIME(MAX(s.last_activity)) FROM sessions s WHERE s.user_id = users.id)';

        $updated = DB::update(
            "UPDATE users SET last_seen_at = {$latest}
             WHERE EXISTS (SELECT 1 FROM sessions s WHERE s.user_id = users.id)
               AND (last_seen_at IS NULL OR last_seen_at < {$latest})"
        );

        $this->info("حُدّث آخر نشاط لـ {$updated} مستخدم.");

        return self::SUCCESS;
    }
}
