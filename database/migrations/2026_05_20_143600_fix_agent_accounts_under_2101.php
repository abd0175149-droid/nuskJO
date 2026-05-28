<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Account;
use App\Models\Agent;

return new class extends Migration
{
    /**
     * إصلاح: نقل حسابات الوكلاء الموجودة تحت دائنون متنوعون (2101)
     * يعمل بناءً على جدول agents مباشرة — لا يعتمد على parent_id
     */
    public function up(): void
    {
        $newParent = Account::where('code', '2101')->first();
        if (!$newParent) {
            // قاعدة بيانات جديدة — لا حاجة للإصلاح
            return;
        }

        // جلب كل الوكلاء
        $agents = Agent::all();
        $nextCode = (int) (Account::where('parent_id', $newParent->id)->max('code') ?? '2110');

        foreach ($agents as $agent) {
            $nextCode++;
            $codeStr = (string) $nextCode;

            if ($agent->account_id) {
                // الحساب موجود — نقله تحت 2101 وغيّر النوع
                $existing = Account::find($agent->account_id);
                if ($existing) {
                    $existing->update([
                        'parent_id' => $newParent->id,
                        'type'      => 'liability',
                        'code'      => $codeStr,
                        'name'      => $agent->name,
                        'is_active' => $agent->is_active,
                    ]);
                    continue; // تم النقل بنجاح
                }
            }

            // الحساب غير موجود أو محذوف — ننشئ جديد
            $account = Account::create([
                'code'      => $codeStr,
                'name'      => $agent->name,
                'parent_id' => $newParent->id,
                'type'      => 'liability',
                'is_active' => $agent->is_active ?? true,
                'currency'  => 'JOD',
                'balance'   => 0,
            ]);

            $agent->update(['account_id' => $account->id]);
        }

        // حذف حساب 1300 القديم إذا لا يزال موجود
        $oldParent = Account::where('code', '1300')->first();
        if ($oldParent) {
            // نقل أي أطفال متبقين
            Account::where('parent_id', $oldParent->id)->update([
                'parent_id' => $newParent->id,
                'type'      => 'liability',
            ]);

            // حذف إذا لم يكن عليه قيود
            $hasEntries = DB::table('journal_entry_lines')
                ->where('account_id', $oldParent->id)->exists();
            if (!$hasEntries) {
                $oldParent->delete();
            } else {
                $oldParent->update(['is_active' => false]);
            }
        }

        // إعادة حساب أرصدة الحسابات المنقولة
        foreach (Account::where('parent_id', $newParent->id)->get() as $acc) {
            $totals = DB::table('journal_entry_lines')
                ->where('account_id', $acc->id)
                ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
                ->first();

            // liability: الرصيد = دائن - مدين
            $balance = ($totals->total_credit ?? 0) - ($totals->total_debit ?? 0);
            $acc->update(['balance' => round($balance, 3)]);
        }
    }

    public function down(): void
    {
        // لا حاجة للتراجع
    }
};
