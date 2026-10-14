<?php

namespace App\Http\Controllers;

use App\Console\Commands\BackupSafeMode;
use App\Jobs\RunBackupJob;
use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\Setting;
use App\Services\Backup\BackupStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * صفحة النسخ الاحتياطي: الحالة وآخر التشغيلات و«نسخة الآن» واعتماد مراجعة ما بعد الاستعادة.
 * لا تنزيل ولا استعادة من الويب عن قصد؛ الاستعادة بـ scripts/backup/restore.sh عبر SSH.
 */
class BackupController extends Controller
{
    public function index(BackupStatus $status)
    {
        $user = auth()->user();
        abort_unless($user->can('backups.view'), 403);

        $runs = Schema::hasTable('backup_runs')
            ? BackupRun::latest('id')->limit(20)->get()->map(fn (BackupRun $r) => [
                'id' => $r->id,
                'reason' => $r->reason,
                'status' => $r->status,
                'snapshot' => $r->snapshot_name,
                'db_bytes' => $r->db_bytes,
                'media_bytes' => $r->media_bytes,
                'integrity' => $r->integrity,
                'error' => $r->error ? mb_substr($r->error, 0, 300) : null,
                'started_at' => $r->started_at?->toIso8601String(),
                'finished_at' => $r->finished_at?->toIso8601String(),
                'collected_at' => $r->collected_at?->toIso8601String(),
                'pulled_at' => $r->pulled_at?->toIso8601String(),
                'drill' => $r->drill_summary['result'] ?? null,
            ])
            : collect();

        return Inertia::render('Settings/Backups', [
            'title' => 'النسخ الاحتياطي',
            'status' => $status->summary(),
            'runs' => $runs,
            'checklist' => BackupSafeMode::CHECKLIST,
            'can' => [
                'run' => $user->can('backups.run'),
                'ack' => $user->can('backups.restore_ack'),
            ],
        ]);
    }

    public function runNow()
    {
        abort_unless(auth()->user()->can('backups.run'), 403);

        if (!Cache::add('backup:manual', true, 600)) {
            return back()->with('error', 'طُلبت نسخة يدوية قبل أقل من 10 دقائق');
        }

        RunBackupJob::dispatch(auth()->id());

        return back()->with('success', 'بدأت النسخة في الخلفية؛ تظهر في الجدول خلال دقائق');
    }

    public function acknowledgeRestore(Request $request, BackupStatus $status)
    {
        abort_unless(auth()->user()->can('backups.restore_ack'), 403);

        $review = $status->review();
        if (!$review) {
            return back()->with('error', 'لا توجد مراجعة معلّقة');
        }

        $checked = (array) $request->input('checked', []);
        $missing = array_diff(array_keys(BackupSafeMode::CHECKLIST), $checked);
        if ($missing) {
            throw ValidationException::withMessages(['checked' => 'أشّر على كل بنود المراجعة قبل الاعتماد']);
        }

        Setting::where('key', BackupSafeMode::REVIEW_KEY)->delete();
        AuditLog::log('backup.restore_ack', 'BackupRun', 0, (string) ($review['snapshot'] ?? ''), [
            'pre_restore' => $review['pre_restore'] ?? null,
            'restored_at' => $review['restored_at'] ?? null,
        ]);

        return back()->with('success', 'اعتُمدت المراجعة. يمكن الآن رفع قفل الإرسال من إعدادات البوت');
    }
}
