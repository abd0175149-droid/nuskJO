<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * أدوات دورة حياة البيانات (E13): هل النسخة الليلية حديثة (لا تقليم بلا نسخة)، وبصمة الرقم لسجل الحذف،
 * وسجل الانكماش الذي يطرحه backup:run (prune وforget يقلّصان الجداول شرعاً)، وسجل المحو الخارجي.
 */
final class Retention
{
    /** نسخة ناجحة مجدولة أو يدوية خلال 26 ساعة؛ بلا جدول النسخ: false */
    public static function backupFresh(): bool
    {
        if (!Schema::hasTable('backup_runs')) {
            return false;
        }

        return DB::table('backup_runs')->where('status', 'ok')->whereIn('reason', ['scheduled', 'manual'])
            ->where('finished_at', '>=', now()->subHours(26))->exists();
    }

    /** بصمة الرقم الموحَّد: لا يُخزَّن الرقم نفسه في سجل الحذف */
    public static function subjectHmac(string $canonicalPhone): string
    {
        return hash_hmac('sha256', 'erasure|'.$canonicalPhone, (string) config('retention.erasure_hmac_key'));
    }

    public static function userIdHmac(string $bsuid): string
    {
        return hash_hmac('sha256', 'erasure-uid|'.$bsuid, (string) config('retention.erasure_hmac_key'));
    }

    /** @param  array<string, int>  $counts  جدول => صفوف حُذفت شرعاً */
    public static function recordShrink(array $counts): void
    {
        $path = (string) config('retention.shrink_ledger');
        File::ensureDirectoryExists(dirname($path), 0700);
        $current = self::shrinkAllowance();
        foreach ($counts as $table => $n) {
            if ($n > 0) {
                $current[$table] = ($current[$table] ?? 0) + (int) $n;
            }
        }
        File::put($path, json_encode($current), true);
        @chmod($path, 0600);
    }

    /** @return array<string, int> */
    public static function shrinkAllowance(): array
    {
        $path = (string) config('retention.shrink_ledger');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) ? array_map('intval', $data) : [];
    }

    public static function clearShrinkAllowance(): void
    {
        $path = (string) config('retention.shrink_ledger');
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** سطر في سجل المحو الخارجي (للإلحاق فقط): بصمة وتاريخ وأعداد، بلا أي بيان شخصي */
    public static function appendErasureLedger(array $entry): void
    {
        $path = (string) config('retention.erasure_ledger');
        File::ensureDirectoryExists(dirname($path), 0700);
        File::append($path, json_encode($entry, JSON_UNESCAPED_UNICODE)."\n", true);
        @chmod($path, 0600);
    }

    /** @return list<array<string, mixed>> */
    public static function erasureLedger(): array
    {
        $path = (string) config('retention.erasure_ledger');
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
