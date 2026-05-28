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

        $lastChild = \App\Models\Account::where('parent_id', $parentId)
            ->where('code', 'like', $parentCode . '%')
            ->orderByDesc('code')
            ->value('code');

        if ($lastChild) {
            $suffix = substr($lastChild, $parentLen);
            $nextSuffix = intval($suffix) + 1;
            return $parentCode . str_pad($nextSuffix, strlen($suffix), '0', STR_PAD_LEFT);
        }

        // لا يوجد أطفال: ابدأ بـ 1
        return $fallback ?: ($parentCode . '1');
    }
}
