<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * عيّنات أحداث الضجيج (دخول فاشل، توقيع مرفوض، 403): حد لكل مفتاح في النافذة، وسقف لكل فعل في الساعة.
 * على مخزن file لا database، فالضجيج لا يكتب في SQLite. العدّاد تقريبي (increment على file غير ذري بين
 * عمّال php-fpm)، والسقف العام يحد أسوأ الحالات.
 */
final class AuditSampler
{
    /** @return array{allowed: bool, suppressed: int} suppressed: ما تجاوز الحد في النافذة السابقة (يُكتب مع أول صف بعدها) */
    public static function allow(string $action, string $key, ?int $limit = null, int $windowSec = 60): array
    {
        $store = Cache::store(config('audit.sampler_store', 'file'));
        $limit ??= (int) config('audit.sample_per_min', 10);
        $hash = sha1($action.'|'.$key);
        $bucket = intdiv(now()->getTimestamp(), $windowSec);

        $current = "audit:k:{$hash}:{$bucket}";
        $store->add($current, 0, $windowSec * 2);
        $count = (int) $store->increment($current);

        $suppressed = 0;
        if ($count === 1) {
            $suppressed = max(0, (int) $store->get("audit:k:{$hash}:".($bucket - 1), 0) - $limit);
        }
        if ($count > $limit) {
            return ['allowed' => false, 'suppressed' => 0];
        }

        $global = "audit:g:{$action}:".now()->format('YmdH');
        $store->add($global, 0, 7200);
        if ((int) $store->increment($global) > (int) config('audit.sample_global_per_hour', 300)) {
            return ['allowed' => false, 'suppressed' => 0];
        }

        return ['allowed' => true, 'suppressed' => $suppressed];
    }
}
