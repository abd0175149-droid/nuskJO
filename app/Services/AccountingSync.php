<?php

namespace App\Services;

class AccountingSync
{
    /**
     * Prevents infinite loops during two-way synchronization.
     * True means a sync is currently in progress.
     */
    public static bool $isSyncing = false;

    /**
     * توليد رقم حساب فرعي بناءً على كود الأب
     * مثال: أب 1200 → أول ابن 12001, ثاني 12002...
     * مثال: أب 2110 → أول ابن 21101, ثاني 21102...
     */
    public static function generateChildCode(int $parentId, string $parentCode, string $fallback = null): string
    {
        $parentLen = strlen($parentCode);

        $codes = \App\Models\Account::where('code', 'like', $parentCode . '%')
            ->where('code', '!=', $parentCode)
            ->pluck('code');

        if ($codes->isNotEmpty()) {
            $suffixes = $codes->map(fn($c) => substr($c, $parentLen));
            $nextSuffix = $suffixes->map(fn($s) => (int)$s)->max() + 1;
            $maxLen = $suffixes->map(fn($s) => strlen($s))->max() ?: 1;
            return $parentCode . str_pad($nextSuffix, $maxLen, '0', STR_PAD_LEFT);
        }

        // لا يوجد أطفال: ابدأ بـ 1
        return $fallback ?: ($parentCode . '1');
    }
}
