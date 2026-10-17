<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * تنظيف يومي (E13.7): مفاتيح الكاش المنتهية في جدول cache (دفعات 1000)، وملفات التصدير الأقدم من exports_days.
 * cache_locks والجلسات يقلّمها Laravel بالقرعة.
 */
class OpsPruneCache extends Command
{
    protected $signature = 'ops:prune-cache';

    protected $description = 'حذف مفاتيح الكاش المنتهية وملفات التصدير القديمة';

    public function handle(): int
    {
        $expired = 0;
        if (config('cache.default') === 'database') {
            $table = config('cache.stores.database.table', 'cache');
            do {
                $keys = DB::table($table)->where('expiration', '<', time())->limit(1000)->pluck('key')->all();
                if ($keys) {
                    $expired += DB::table($table)->whereIn('key', $keys)->delete();
                }
            } while (count($keys) === 1000);
        }

        $files = 0;
        $dir = storage_path('app/exports');
        if (is_dir($dir)) {
            $cutoff = now()->subDays((int) config('retention.exports_days', 7))->timestamp;
            foreach (File::files($dir) as $file) {
                if ($file->getMTime() < $cutoff && File::delete($file->getPathname())) {
                    $files++;
                }
            }
        }
        $this->line("مفاتيح منتهية: {$expired} · ملفات تصدير: {$files}");

        return self::SUCCESS;
    }
}
