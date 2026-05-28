<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Account;
use App\Models\Agent;

return new class extends Migration
{
    /**
     * إصلاح: إنشاء حساب "الوكلاء" (2110) تحت دائنون متنوعون (2101)
     * ثم نقل حسابات الوكلاء تحته
     *
     * الهيكل النهائي:
     * 2101 دائنون متنوعون
     *   └── 2110 الوكلاء
     *       ├── 2111 وكيل أ
     *       └── 2112 وكيل ب
     */
    public function up(): void
    {
        $creditors = Account::where('code', '2101')->first();
        if (!$creditors) {
            // قاعدة بيانات جديدة — لا حاجة للإصلاح
            return;
        }

        // 1. إنشاء حساب "الوكلاء" (2110) تحت دائنون متنوعون
        $agentsParent = Account::firstOrCreate(
            ['code' => '2110'],
            [
                'name'      => 'الوكلاء',
                'type'      => 'liability',
                'parent_id' => $creditors->id,
                'is_system' => true,
                'is_active' => true,
                'currency'  => 'JOD',
                'balance'   => 0,
            ]
        );

        // 2. نقل أي حسابات وكلاء موجودة مباشرة تحت 2101 → إلى تحت 2110
        Account::where('parent_id', $creditors->id)
            ->where('id', '!=', $agentsParent->id)
            ->update(['parent_id' => $agentsParent->id]);

        // 3. جلب كل الوكلاء وضمان وجود حساب لكل واحد تحت 2110
        $agents = Agent::all();
        $nextCode = (int) (Account::where('parent_id', $agentsParent->id)->max('code') ?? '2110');

        foreach ($agents as $agent) {
            if ($agent->account_id) {
                $existing = Account::find($agent->account_id);
                if ($existing) {
                    // تأكد أنه تحت 2110 ونوعه liability
                    if ($existing->parent_id != $agentsParent->id || $existing->type !== 'liability') {
                        $nextCode++;
                        $existing->update([
                            'parent_id' => $agentsParent->id,
                            'type'      => 'liability',
                            'code'      => (string) $nextCode,
                            'name'      => $agent->name,
                        ]);
                    }
                    continue;
                }
            }

            // حساب غير موجود — ننشئ جديد
            $nextCode++;
            $account = Account::create([
                'code'      => (string) $nextCode,
                'name'      => $agent->name,
                'parent_id' => $agentsParent->id,
                'type'      => 'liability',
                'is_active' => $agent->is_active ?? true,
                'currency'  => 'JOD',
                'balance'   => 0,
            ]);
            $agent->update(['account_id' => $account->id]);
        }

        // 4. حذف حساب 1300 القديم إذا لا يزال موجود
        $old = Account::where('code', '1300')->first();
        if ($old) {
            // نقل أي أطفال متبقين
            Account::where('parent_id', $old->id)->update([
                'parent_id' => $agentsParent->id,
                'type'      => 'liability',
            ]);
            $hasEntries = DB::table('journal_entry_lines')
                ->where('account_id', $old->id)->exists();
            if (!$hasEntries) {
                $old->delete();
            } else {
                $old->update(['is_active' => false]);
            }
        }

        // 5. إعادة حساب الأرصدة
        foreach (Account::where('parent_id', $agentsParent->id)->get() as $acc) {
            $totals = DB::table('journal_entry_lines')
                ->where('account_id', $acc->id)
                ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
                ->first();
            $balance = ($totals->total_credit ?? 0) - ($totals->total_debit ?? 0);
            $acc->update(['balance' => round($balance, 3)]);
        }

        // رصيد الأب
        $parentBalance = Account::where('parent_id', $agentsParent->id)->sum('balance');
        $agentsParent->update(['balance' => round($parentBalance, 3)]);
    }

    public function down(): void {}
};
