<?php

namespace App\Services\Backup;

/** استخدام القرص؛ صنف منفصل حتى يُستبدل في الاختبارات. */
class DiskUsage
{
    /** نسبة الاستخدام المئوية للقرص الذي عليه المسار، أو null إن تعذّرت القراءة. */
    public function usedPercent(string $path): ?float
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if (!$total || $free === false) {
            return null;
        }

        return round(100 * ($total - $free) / $total, 1);
    }

    public function directoryBytes(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $bytes = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $bytes += $file->isFile() ? $file->getSize() : 0;
        }

        return $bytes;
    }
}
