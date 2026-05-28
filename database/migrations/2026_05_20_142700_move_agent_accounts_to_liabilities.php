<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Account;

return new class extends Migration
{
    /**
     * نقل حسابات الوكلاء من الأصول (1300) إلى الالتزامات (2101 دائنون متنوعون)
     */
    public function up(): void
    {
        // 1. إيجاد الحسابات المعنية
        $oldParent = Account::where('code', '1300')->first();       // أرصدة الوكلاء (asset)
        $newParent = Account::where('code', '2101')->first();       // دائنون متنوعون (liability)

        if (!$oldParent || !$newParent) {
            // إذا لم يوجد أحدهما، لا ننفذ شيء
            return;
        }

        // 2. نقل كل الحسابات الفرعية من 1300 إلى 2101
        $childAccounts = Account::where('parent_id', $oldParent->id)->get();

        $nextCode = (int) (Account::where('parent_id', $newParent->id)->max('code') ?? '2110');
        
        foreach ($childAccounts as $account) {
            $nextCode++;
            $account->update([
                'parent_id' => $newParent->id,
                'type'      => 'liability',
                'code'      => (string) $nextCode,
            ]);
        }

        // 3. حذف الحساب الأب القديم (1300) إذا لم يعد له أطفال
        if (Account::where('parent_id', $oldParent->id)->count() === 0) {
            // التأكد أنه لا يوجد قيود مسجلة عليه مباشرة
            $hasEntries = DB::table('journal_entry_lines')
                ->where('account_id', $oldParent->id)->exists();
            
            if (!$hasEntries) {
                $oldParent->delete();
            } else {
                // فقط إلغاء تفعيله
                $oldParent->update(['is_active' => false, 'name' => '[محذوف] أرصدة الوكلاء']);
            }
        }

        // 4. إعادة حساب الأرصدة المخبأة للحسابات المنقولة
        //    بما أن النوع تغير من asset إلى liability، الرصيد يُحسب عكسياً
        foreach (Account::where('parent_id', $newParent->id)->get() as $acc) {
            $totals = DB::table('journal_entry_lines')
                ->where('account_id', $acc->id)
                ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
                ->first();

            // liability: الرصيد = دائن - مدين
            $balance = ($totals->total_credit ?? 0) - ($totals->total_debit ?? 0);
            $acc->update(['balance' => round($balance, 3)]);
        }

        // إعادة حساب رصيد الحساب الأب (2101)
        $parentBalance = Account::where('parent_id', $newParent->id)->sum('balance');
        $newParent->update(['balance' => round($parentBalance, 3)]);
    }

    /**
     * التراجع: نقل الحسابات مرة أخرى إلى 1300
     */
    public function down(): void
    {
        // إعادة إنشاء 1300 إذا محذوف
        $oldParent = Account::firstOrCreate(
            ['code' => '1300'],
            [
                'name' => 'أرصدة الوكلاء',
                'type' => 'asset',
                'parent_id' => Account::where('code', '1100')->value('id'),
                'is_system' => true,
                'currency' => 'JOD',
            ]
        );

        $newParent = Account::where('code', '2101')->first();
        if (!$newParent) return;

        // نقل الحسابات الفرعية المرتبطة بالوكلاء فقط
        $agentAccountIds = DB::table('agents')->whereNotNull('account_id')->pluck('account_id');
        
        $nextCode = 1300;
        foreach (Account::where('parent_id', $newParent->id)
                    ->whereIn('id', $agentAccountIds)->get() as $account) {
            $nextCode++;
            $account->update([
                'parent_id' => $oldParent->id,
                'type'      => 'asset',
                'code'      => (string) $nextCode,
            ]);
        }
    }
};
