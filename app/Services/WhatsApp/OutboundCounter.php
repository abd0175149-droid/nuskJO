<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaMessage;
use App\Services\Ops\OpsHealth;
use Illuminate\Support\Carbon;

/**
 * عدّاد الصادر (E24): من wa_messages نفسها لا من عدّاد كاش، فيصمد لإعادة التشغيل. اليوم والشهر بتوقيت عمّان
 * (created_at بتوقيت UTC، فبداية يوم عمّان تُحوَّل إلى UTC قبل المقارنة). غير المسلَّم خارج العدّ (E19).
 *
 * - today(): الصادر اليوم بالمصدر (bot وstaff وsystem وrule…)، والتجريبي (test، E23) في سطر مستقل خارج الإجمالي.
 * - automatedToday(): الرسائل الآلية بنوعها (بادئة SendKey: reply وoutage وconsent…) لكل منها ميزانية يقرها المالك.
 * (تسعير ميتا ورسائل الخدمة المحتسبة أُزيلا مع ميتا، 2026-11-06؛ حدود الإرسال في Sending\SendGovernor)
 */
final class OutboundCounter
{
    public const TZ = 'Asia/Amman';

    /** مصادر آلية: لها مفتاح SendKey ونوع من بادئته */
    public const AUTOMATED_SOURCES = ['bot', 'system', 'rule'];

    /** المصدر التجريبي (E23): يُعدّ مستقلاً ولا يدخل الإجمالي ولا التنبيه */
    public const TEST_SOURCE = 'test';

    public static function dayStart(): Carbon
    {
        return Carbon::now(self::TZ)->startOfDay()->utc();
    }

    public static function monthStart(): Carbon
    {
        return Carbon::now(self::TZ)->startOfMonth()->utc();
    }

    /** @return array{day: string, by_source: array<string, int>, total: int, test: int} */
    public static function today(bool $fresh = false): array
    {
        $build = function (): array {
            $rows = WaMessage::where('direction', 'out')->where('created_at', '>=', self::dayStart())->excludeUndelivered()
                ->groupBy('source')->selectRaw('source, COUNT(*) as n')->pluck('n', 'source')->map(fn ($n) => (int) $n)->all();
            $test = (int) ($rows[self::TEST_SOURCE] ?? 0);
            unset($rows[self::TEST_SOURCE]);
            ksort($rows);

            return ['day' => Carbon::now(self::TZ)->toDateString(), 'by_source' => $rows, 'total' => array_sum($rows), 'test' => $test];
        };

        return $fresh ? $build() : OpsHealth::store()->remember('wa:outbound:today:'.Carbon::now(self::TZ)->format('Ymd'), 60, $build);
    }

    /**
     * الرسائل الآلية اليوم بنوعها (ما قبل أول «:» في send_key). بلا مفتاح (المسار القديم قبل دفتر الإرسال) = other.
     *
     * @return array<string, int>
     */
    public static function automatedToday(): array
    {
        $rows = WaMessage::where('direction', 'out')->whereIn('source', self::AUTOMATED_SOURCES)
            ->where('created_at', '>=', self::dayStart())->excludeUndelivered()
            ->groupByRaw("CASE WHEN send_key IS NULL OR instr(send_key, ':') = 0 THEN 'other' ELSE substr(send_key, 1, instr(send_key, ':') - 1) END")
            ->selectRaw("CASE WHEN send_key IS NULL OR instr(send_key, ':') = 0 THEN 'other' ELSE substr(send_key, 1, instr(send_key, ':') - 1) END as kind, COUNT(*) as n")
            ->pluck('n', 'kind')->map(fn ($n) => (int) $n)->all();
        ksort($rows);

        return $rows;
    }

    /**
     * كل نوع آلي مع ميزانيته من config('ops.automated_budgets') (المعرّف بلا رسائل اليوم يظهر صفراً).
     *
     * @return list<array{kind: string, count: int, budget: ?int, over: bool}>
     */
    public static function automatedWithBudgets(?array $counts = null): array
    {
        $counts ??= self::automatedToday();
        $budgets = (array) config('ops.automated_budgets', []);
        $out = [];
        foreach (array_unique([...array_keys($budgets), ...array_keys($counts)]) as $kind) {
            $budget = isset($budgets[$kind]) && is_numeric($budgets[$kind]) ? (int) $budgets[$kind] : null;
            $n = (int) ($counts[$kind] ?? 0);
            $out[] = ['kind' => (string) $kind, 'count' => $n, 'budget' => $budget, 'over' => $budget !== null && $n > $budget];
        }

        return $out;
    }

    /** سطر الإعدادات وصفحة التحليلات: الصادر اليوم بمصادره، والآلي بميزانياته، وحد التنبيه اليومي */
    public static function summary(?WaBotSetting $s = null): array
    {
        $s ??= WaBotSetting::current();

        return [
            'today' => self::today(),
            'automated' => self::automatedWithBudgets(),
            'daily_alert_threshold' => (int) ($s->wa_daily_alert_threshold ?? 300),
        ];
    }
}
