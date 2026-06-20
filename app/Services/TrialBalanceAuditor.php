<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * مدقّق ميزان المراجعة
 *
 * يكتشف القيود التي تكسر توازن ميزان المراجعة ويصنّفها ويقترح حلولها.
 *
 * السبب الجذري للاختلال: ميزان المراجعة يعرض الحسابات الورقية النشطة فقط
 * (whereDoesntHave('children') + is_active). فأي سطر قيد مُرحَّل على:
 *   - حساب أب (صار له أبناء بعد الترحيل)، أو
 *   - حساب موقوف (is_active = 0)، أو
 *   - حساب محذوف (account_id بلا حساب مقابل)
 * يُستبعد من الميزان فيظهر فرق رغم أن دفتر اليومية نفسه متوازن.
 *
 * كما يكتشف القيود غير المتوازنة (مدين ≠ دائن) إن وُجدت.
 */
class TrialBalanceAuditor
{
    /**
     * فحص شامل لسلامة ميزان المراجعة (على كل الفترات — المشكلة بنيوية لا زمنية).
     */
    public static function audit(): array
    {
        $accounts = Account::select('id', 'code', 'name', 'type', 'is_active', 'parent_id')
            ->get()->keyBy('id');

        // خريطة الأبناء: parent_id => [child_id, ...]
        $childIds = [];
        foreach ($accounts as $a) {
            if ($a->parent_id) {
                $childIds[$a->parent_id][] = $a->id;
            }
        }
        $hasChildren = fn ($id) => !empty($childIds[$id] ?? []);
        $isLeafActive = fn ($a) => $a && $a->is_active && !$hasChildren($a->id);

        // الحسابات الورقية النشطة — وجهات النقل المسموحة
        $leafAccounts = $accounts
            ->filter(fn ($a) => $isLeafActive($a))
            ->map(fn ($a) => [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type,
            ])
            ->sortBy('code')->values();

        // كل سطور القيود مع بيانات القيد
        $lines = DB::table('journal_entry_lines as l')
            ->join('journal_entries as je', 'je.id', '=', 'l.journal_entry_id')
            ->select(
                'l.id as line_id', 'l.account_id', 'l.debit', 'l.credit',
                'je.id as entry_id', 'je.entry_number', 'je.entry_date',
                'je.description', 'je.reference_type'
            )
            ->orderBy('je.entry_date')
            ->get();

        $problemLabels = [
            'parent_account'   => 'حساب أب (له فروع) — يُستبعد من الميزان',
            'inactive_account' => 'حساب موقوف — يُستبعد من الميزان',
            'missing_account'  => 'حساب محذوف — لا مقابل له',
        ];

        $issues = [];
        foreach ($lines as $ln) {
            $acc = $accounts->get($ln->account_id);

            $problem = null;
            if (!$acc) {
                $problem = 'missing_account';
            } elseif (!$acc->is_active) {
                $problem = 'inactive_account';
            } elseif ($hasChildren($ln->account_id)) {
                $problem = 'parent_account';
            }
            if (!$problem) {
                continue;
            }

            // وجهات مقترحة: لو الحساب أب → فروعه الورقية النشطة أولاً
            $preferred = [];
            if ($problem === 'parent_account') {
                foreach (($childIds[$ln->account_id] ?? []) as $cid) {
                    if ($isLeafActive($accounts->get($cid))) {
                        $preferred[] = $cid;
                    }
                }
            }

            $debit  = round((float) $ln->debit, 3);
            $credit = round((float) $ln->credit, 3);

            $issues[] = [
                'line_id' => $ln->line_id,
                'debit'   => $debit,
                'credit'  => $credit,
                'amount'  => $debit > 0 ? $debit : $credit,
                'side'    => $debit > 0 ? 'debit' : 'credit',
                'problem' => $problem,
                'problem_label' => $problemLabels[$problem],
                'account' => $acc ? [
                    'id' => $acc->id, 'code' => $acc->code, 'name' => $acc->name, 'type' => $acc->type,
                ] : null,
                'account_id' => $ln->account_id,
                'entry' => [
                    'id'     => $ln->entry_id,
                    'number' => $ln->entry_number,
                    'date'   => substr((string) $ln->entry_date, 0, 10),
                    'description'    => $ln->description,
                    'reference_type' => $ln->reference_type,
                ],
                'preferred' => $preferred,
            ];
        }

        // قيود غير متوازنة (مدين ≠ دائن) — نظرياً مستحيلة لكن للأمان
        $unbalancedEntries = DB::table('journal_entries as je')
            ->leftJoin(
                DB::raw('(SELECT journal_entry_id, SUM(debit) sd, SUM(credit) sc
                          FROM journal_entry_lines GROUP BY journal_entry_id) s'),
                's.journal_entry_id', '=', 'je.id'
            )
            ->whereRaw('ROUND(COALESCE(s.sd,0),3) <> ROUND(COALESCE(s.sc,0),3)')
            ->select(
                'je.id', 'je.entry_number', 'je.entry_date', 'je.description',
                DB::raw('COALESCE(s.sd,0) as sd'), DB::raw('COALESCE(s.sc,0) as sc')
            )
            ->orderBy('je.entry_date')
            ->get()
            ->map(fn ($e) => [
                'id'     => $e->id,
                'number' => $e->entry_number,
                'date'   => substr((string) $e->entry_date, 0, 10),
                'description' => $e->description,
                'debit_sum'   => round((float) $e->sd, 3),
                'credit_sum'  => round((float) $e->sc, 3),
                'diff'        => round((float) $e->sd - (float) $e->sc, 3),
            ])->values();

        // إجمالي دفتر اليومية (مرجع التحقق)
        $g = DB::table('journal_entry_lines')
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        // صافي ما تستبعده المشاكل من الميزان (debit - credit)
        $excludedNet = round(array_sum(array_map(
            fn ($i) => $i['debit'] - $i['credit'], $issues
        )), 3);

        return [
            'issues' => $issues,
            'unbalanced_entries' => $unbalancedEntries,
            'leaf_accounts' => $leafAccounts,
            'global_debit'  => round((float) $g->d, 3),
            'global_credit' => round((float) $g->c, 3),
            'excluded_net'  => $excludedNet,
            'has_issues'    => count($issues) > 0 || $unbalancedEntries->count() > 0,
        ];
    }
}
