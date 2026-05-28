<?php

namespace App\Observers;

use App\Models\Employee;
use App\Models\Account;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\AccountingSync;
use Illuminate\Support\Facades\Log;

class EmployeeObserver
{
    /**
     * عند إنشاء أو تحديث موظف:
     * 1. إنشاء حساب فرعي تحت 1300 (ذمم الموظفين)
     * 2. إنشاء أرصدة إجازات تلقائية حسب country
     */
    public function saved(Employee $employee): void
    {
        if (AccountingSync::$isSyncing) return;
        AccountingSync::$isSyncing = true;

        try {
            $this->syncAccount($employee);
            $this->syncLeaveBalances($employee);
        } catch (\Throwable $e) {
            Log::error("[EmployeeObserver] خطأ: {$e->getMessage()}");
        } finally {
            AccountingSync::$isSyncing = false;
        }
    }

    /**
     * عند حذف موظف — حذف الحساب المحاسبي
     */
    public function deleted(Employee $employee): void
    {
        if (AccountingSync::$isSyncing) return;
        AccountingSync::$isSyncing = true;

        try {
            if ($employee->account_id) {
                Account::where('id', $employee->account_id)->delete();
            }
        } finally {
            AccountingSync::$isSyncing = false;
        }
    }

    /**
     * ربط الموظف بحساب فرعي تحت 1300 (ذمم الموظفين)
     * نفس نمط AgentObserver تماماً
     */
    private function syncAccount(Employee $employee): void
    {
        $parentAccount = Account::where('code', '1300')->first();

        // إنشاء تلقائي إذا لم يوجد حساب 1300
        if (!$parentAccount) {
            Log::info('[EmployeeObserver] حساب 1300 غير موجود — إنشاء تلقائي');
            $currentAssets = Account::where('code', '1100')->first();
            if ($currentAssets) {
                $parentAccount = Account::create([
                    'code' => '1300', 'name' => 'ذمم الموظفين (سلف)',
                    'type' => 'asset', 'parent_id' => $currentAssets->id,
                    'is_system' => true, 'is_active' => true, 'currency' => 'JOD', 'balance' => 0,
                ]);
            }
        }

        if ($parentAccount) {
            if (!$employee->account_id) {
                $newCode = AccountingSync::generateChildCode($parentAccount->id, $parentAccount->code);

                $userName = $employee->user?->name ?? 'موظف';
                $account = Account::create([
                    'code' => $newCode,
                    'name' => $userName,
                    'parent_id' => $parentAccount->id,
                    'type' => 'asset',
                    'is_active' => $employee->is_active,
                    'currency' => $employee->currency ?? 'JOD',
                ]);

                $employee->update(['account_id' => $account->id]);
                Log::info("[EmployeeObserver] ✅ حساب {$newCode} للموظف {$userName}");
            } else {
                // تحديث اسم الحساب ليطابق اسم المستخدم
                Account::where('id', $employee->account_id)->update([
                    'name' => $employee->user?->name ?? 'موظف',
                    'is_active' => $employee->is_active,
                ]);
            }
        } else {
            Log::error('[EmployeeObserver] فشل إنشاء حساب 1300!');
        }
    }

    /**
     * إنشاء أرصدة إجازات تلقائية حسب بلد الموظف (SA/JO)
     */
    private function syncLeaveBalances(Employee $employee): void
    {
        $currentYear = now()->year;

        // فحص هل يوجد أرصدة للسنة الحالية
        $existingCount = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', $currentYear)
            ->count();

        if ($existingCount > 0) return; // الأرصدة موجودة مسبقاً

        // جلب أنواع الإجازات المتاحة لبلد الموظف
        $leaveTypes = LeaveType::active()
            ->forCountry($employee->country)
            ->get();

        foreach ($leaveTypes as $type) {
            LeaveBalance::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'year' => $currentYear,
                'total_days' => $type->default_days,
                'used_days' => 0,
                'remaining_days' => $type->default_days,
            ]);
        }

        Log::info("[EmployeeObserver] ✅ أرصدة إجازات ({$leaveTypes->count()}) للموظف {$employee->employee_number}");
    }
}
