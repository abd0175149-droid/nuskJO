<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مطابقة الدفترين: الدفتر المبسّط (BalanceService / balance_jod & balance_sar)
 * مقابل دفتر القيود المزدوج (AccountingService / journal_entries & accounts.balance).
 *
 * يكتشف أنواع الانحراف:
 *  1) مستند معتمد بلا قيد محاسبي فعّال (أخطر نوع — مثل ابتلاع استثناء قديم).
 *  2) اختلاف رصيد عميل بين balance_jod ورصيد حسابه المحاسبي (نفس العملة والإشارة).
 *  3) انحراف الرصيد المخبأ accounts.balance عن مجموع سطور القيود.
 *  4) اختلال ميزان المراجعة (عبر TrialBalanceAuditor).
 */
class LedgerReconciliationService
{
    /** هامش التسامح للفروقات (دينار) */
    const TOLERANCE = 0.01;

    /** الحد الأقصى لعدد العناصر المعروضة لكل فئة (تفادي قوائم ضخمة) */
    const MAX_PER_CATEGORY = 50;

    public static function reconcile(): array
    {
        $issues = [];

        // ============================================================
        // 1) مستندات معتمدة بلا قيد محاسبي فعّال
        // ============================================================
        $docs = [
            ['type' => 'invoice',      'table' => 'invoices',      'number' => 'invoice_number',      'label' => 'فاتورة معتمدة بلا قيد محاسبي'],
            ['type' => 'receipt',      'table' => 'receipts',      'number' => 'receipt_number',      'label' => 'سند قبض معتمد بلا قيد محاسبي'],
            ['type' => 'transfer',     'table' => 'transfers',     'number' => 'transfer_number',     'label' => 'حوالة معتمدة بلا قيد محاسبي'],
            ['type' => 'disbursement', 'table' => 'disbursements', 'number' => 'disbursement_number', 'label' => 'سند صرف معتمد بلا قيد محاسبي'],
            ['type' => 'expense',      'table' => 'expenses',      'number' => 'expense_number',      'label' => 'مصروف معتمد بلا قيد محاسبي'],
        ];

        foreach ($docs as $d) {
            if (!Schema::hasTable($d['table'])) {
                continue;
            }
            $missing = DB::table($d['table'] . ' as t')
                ->where('t.status', 'approved')
                ->whereNotExists(function ($q) use ($d) {
                    $q->select(DB::raw(1))->from('journal_entries as je')
                        ->where('je.reference_type', $d['type'])
                        ->whereColumn('je.reference_id', 't.id')
                        ->where('je.is_reversed', 0);
                })
                ->orderBy('t.id')
                ->limit(self::MAX_PER_CATEGORY)
                ->get(['t.id', DB::raw("t.{$d['number']} as number")]);

            foreach ($missing as $m) {
                $issues[] = [
                    'category' => 'missing_journal_entry',
                    'label'    => $d['label'],
                    'ref'      => $m->number,
                    'id'       => $m->id,
                ];
            }
        }

        // ============================================================
        // تجهيز أرصدة الحسابات ومجاميع القيود (تُستخدم في 2 و 3)
        // ============================================================
        $accounts = Account::get(['id', 'code', 'name', 'type', 'balance']);
        $accountBalances = $accounts->pluck('balance', 'id');

        $sums = DB::table('journal_entry_lines')
            ->select('account_id', DB::raw('SUM(debit) AS d'), DB::raw('SUM(credit) AS c'))
            ->groupBy('account_id')->get()->keyBy('account_id');

        // ============================================================
        // 2) اختلاف رصيد العميل بين الدفترين (كلاهما بالدينار)
        // ============================================================
        $clients = Client::whereNotNull('account_id')
            ->get(['id', 'name', 'code', 'balance_jod', 'account_id']);

        foreach ($clients as $c) {
            $journal = (float) ($accountBalances[$c->account_id] ?? 0);
            $simple  = (float) $c->balance_jod;
            $diff    = round($journal - $simple, 3);
            if (abs($diff) > self::TOLERANCE) {
                $issues[] = [
                    'category' => 'client_balance_mismatch',
                    'label'    => 'اختلاف رصيد عميل بين الدفترين',
                    'ref'      => "{$c->code} - {$c->name}",
                    'id'       => $c->id,
                    'simple'   => $simple,
                    'journal'  => round($journal, 3),
                    'diff'     => $diff,
                ];
            }
        }

        // ============================================================
        // 3) انحراف الرصيد المخبأ للحساب عن مجموع سطور القيود
        // ============================================================
        foreach ($accounts as $a) {
            $row    = $sums->get($a->id);
            $debit  = (float) ($row->d ?? 0);
            $credit = (float) ($row->c ?? 0);
            $computed = in_array($a->type, ['asset', 'expense'])
                ? ($debit - $credit)
                : ($credit - $debit);
            $diff = round($computed - (float) $a->balance, 3);
            if (abs($diff) > self::TOLERANCE) {
                $issues[] = [
                    'category' => 'account_balance_drift',
                    'label'    => 'انحراف الرصيد المخبأ للحساب عن القيود',
                    'ref'      => "{$a->code} - {$a->name}",
                    'id'       => $a->id,
                    'cached'   => round((float) $a->balance, 3),
                    'computed' => round($computed, 3),
                    'diff'     => $diff,
                ];
            }
        }

        // ============================================================
        // 4) اختلال ميزان المراجعة / قيود شاذّة
        // ============================================================
        $tb = TrialBalanceAuditor::audit();
        if (!empty($tb['has_issues'])) {
            $count = count($tb['issues']) + $tb['unbalanced_entries']->count();
            $issues[] = [
                'category' => 'trial_balance',
                'label'    => 'ميزان المراجعة غير متوازن أو فيه قيود شاذّة',
                'ref'      => "عدد الحالات: {$count}",
                'id'       => null,
            ];
        }

        $byCategory = collect($issues)->groupBy('category')->map->count()->toArray();

        return [
            'issues'         => $issues,
            'count'          => count($issues),
            'has_divergence' => count($issues) > 0,
            'by_category'    => $byCategory,
        ];
    }
}
