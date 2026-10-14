<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * مراجعة يومية لأسبوع وضع التسجيل (AUTHZ_MODE=log): ما الذي كان سيُرفض، ولأي دور، وكم مرة.
 * «would_deny» لمستخدم يحتاج المسار فعلاً تعني منح صلاحية لدوره قبل التحويل إلى enforce؛
 * و«unmapped» تعني مساراً بلا صلاحية معلنة في routes/web.php.
 *
 *   php artisan authz:report --hours=24
 */
class AuthzReport extends Command
{
    protected $signature = 'authz:report {--hours=24}';

    protected $description = 'ملخص رفض الصلاحيات المسجّل (authz.*) حسب المسار والدور';

    public function handle(): int
    {
        $rows = AuditLog::where('action', 'like', 'authz.%')
            ->where('created_at', '>=', now()->subHours(max(1, (int) $this->option('hours'))))
            ->get(['action', 'entity_label', 'changes', 'user_id']);

        if ($rows->isEmpty()) {
            $this->info('لا شيء مسجّل في المدة.');

            return self::SUCCESS;
        }

        $grouped = $rows->groupBy(fn ($r) => implode('|', [$r->action, $r->entity_label, $r->changes['role'] ?? '—', $r->changes['perm'] ?? '—']))
            ->map(fn ($g, $key) => [...explode('|', $key), $g->count(), $g->pluck('user_id')->unique()->count()])
            ->sortByDesc(fn ($r) => $r[4])
            ->values()
            ->all();

        $this->table(['الفعل', 'المسار', 'الدور', 'الصلاحية', 'مرات (ساعات)', 'مستخدمون'], $grouped);

        return self::SUCCESS;
    }
}
