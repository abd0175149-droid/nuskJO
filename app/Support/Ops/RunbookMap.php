<?php

namespace App\Support\Ops;

use App\Support\FeatureFlags;

/**
 * من مفتاح التنبيه إلى كتيّبه في docs/RUNBOOK.md (E25). الربط في config/runbooks.php، والمطابقة بحدود المقاطع
 * والأطول يغلب، فـ 'meta:send:190' يعيد RB-02 و'jobfailed:ProcessWhatsAppReply' يعيد RB-11، والمجهول null بلا استثناء.
 * الإحالة نص يُلحق بالإشعار فقط: الوثائق لا تُخدم من الموقع، ولا رقم عميل ولا نص رسالة يُضاف معها.
 */
final class RunbookMap
{
    public const PATTERN = '/^RB-\d{2}$/';

    public static function for(string $key): ?string
    {
        return self::match((array) config('runbooks.alerts', []), $key);
    }

    /** الكتيّب عند فشل مهمة مجدولة باسمها في القائمة الموحدة (docs/ops/load-budget.md) */
    public static function forSchedule(string $task): ?string
    {
        return self::match((array) config('runbooks.schedules', []), $task);
    }

    /** @param array<string, string> $map */
    private static function match(array $map, string $key): ?string
    {
        $best = null;
        $length = -1;
        foreach ($map as $prefix => $runbook) {
            $prefix = (string) $prefix;
            if (($key === $prefix || str_starts_with($key, $prefix.':')) && strlen($prefix) > $length) {
                [$best, $length] = [(string) $runbook, strlen($prefix)];
            }
        }

        return $best;
    }

    /** « — الكتيب RB-NN» بالعلم alert_runbook_refs وحده؛ وإلا نص فارغ فلا يتغير الإشعار */
    public static function suffix(?string $runbook): string
    {
        if ($runbook === null || preg_match(self::PATTERN, $runbook) !== 1) {
            return '';
        }

        // التنبيه يصل والقاعدة متعثرة أو الأعلام مزيّفة في اختبار: العلم مطفأ حينها
        return rescue(fn () => FeatureFlags::on('alert_runbook_refs'), false, false) ? ' — الكتيب '.$runbook : '';
    }
}
