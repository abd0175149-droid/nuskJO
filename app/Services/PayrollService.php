<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AdvanceInstallment;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\PayrollItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    /**
     * توليد مسير رواتب لشهر/سنة/عملة
     */
    public static function generate(int $month, int $year, string $currency): Payroll
    {
        // التحقق من عدم وجود مسير مكرر
        $exists = Payroll::where('month', $month)
            ->where('year', $year)
            ->where('currency', $currency)
            ->whereIn('status', ['draft', 'pending', 'approved'])
            ->exists();

        if ($exists) {
            throw new \Exception("يوجد مسير رواتب بالفعل لهذه الفترة ({$month}/{$year} - {$currency})");
        }

        return DB::transaction(function () use ($month, $year, $currency) {
            $payroll = Payroll::create([
                'payroll_number' => NumberingService::generate('PAY'),
                'month' => $month,
                'year' => $year,
                'currency' => $currency,
                'total_basic' => 0,
                'total_allowances' => 0,
                'total_deductions' => 0,
                'total_net' => 0,
                'employees_count' => 0,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);

            // جلب الموظفين النشطين بنفس العملة
            $employees = Employee::active()
                ->byCurrency($currency)
                ->with(['shift', 'attendances', 'advances.installments'])
                ->get();

            foreach ($employees as $employee) {
                self::calculateEmployeePayroll($payroll, $employee, $month, $year);
            }

            self::updatePayrollTotals($payroll);

            return $payroll->fresh(['items.employee.user']);
        });
    }

    /**
     * حساب راتب موظف واحد
     */
    private static function calculateEmployeePayroll(Payroll $payroll, Employee $employee, int $month, int $year): PayrollItem
    {
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();

        // ===== الاستحقاقات =====
        $basicSalary = (float) $employee->basic_salary;
        $housingAllowance = (float) $employee->housing_allowance;
        $transportAllowance = (float) $employee->transport_allowance;
        $otherAllowance = (float) $employee->other_allowance;

        // حساب العمل الإضافي
        $overtimeAmount = self::calculateOvertime($employee, $month, $year);

        // ===== الخصومات =====

        // 1. خصم التأخير
        $lateDeduction = self::calculateLateDeduction($employee, $month, $year);

        // 2. خصم الغياب
        $absenceDeduction = self::calculateAbsenceDeduction($employee, $month, $year);

        // 3. خصم الإجازات غير المدفوعة
        $unpaidLeaveDeduction = self::calculateUnpaidLeaveDeduction($employee, $month, $year);

        // 4. خصم قسط السلفة
        $advanceDeduction = self::calculateAdvanceDeduction($employee, $month, $year);

        // 5. خصم المخالفات
        $penaltyDeduction = self::calculatePenaltyDeduction($employee, $month, $year);

        // ===== الإجماليات =====
        $totalEarnings = $basicSalary + $housingAllowance + $transportAllowance + $otherAllowance + $overtimeAmount;
        $totalDeductions = $lateDeduction + $absenceDeduction + $unpaidLeaveDeduction + $advanceDeduction + $penaltyDeduction;
        $netSalary = $totalEarnings - $totalDeductions;

        return PayrollItem::create([
            'payroll_id' => $payroll->id,
            'employee_id' => $employee->id,
            'basic_salary' => $basicSalary,
            'housing_allowance' => $housingAllowance,
            'transport_allowance' => $transportAllowance,
            'other_allowance' => $otherAllowance,
            'overtime_amount' => $overtimeAmount,
            'bonus' => 0,
            'late_deduction' => $lateDeduction,
            'absence_deduction' => $absenceDeduction,
            'unpaid_leave_deduction' => $unpaidLeaveDeduction,
            'advance_deduction' => $advanceDeduction,
            'penalty_deduction' => $penaltyDeduction,
            'total_earnings' => $totalEarnings,
            'total_deductions' => $totalDeductions,
            'net_salary' => $netSalary,
        ]);
    }

    /**
     * حساب العمل الإضافي
     */
    private static function calculateOvertime(Employee $employee, int $month, int $year): float
    {
        $totalOvertimeMinutes = Attendance::where('employee_id', $employee->id)
            ->whereMonth('date', $month)
            ->whereYear('date', $year)
            ->sum('overtime_minutes');

        if ($totalOvertimeMinutes <= 0) return 0;

        $overtimeHours = $totalOvertimeMinutes / 60;

        if ($employee->overtime_calc_method === 'fixed') {
            return round($overtimeHours * (float) $employee->overtime_hourly_rate, 3);
        }

        // multiplier: (basic/30/8) × multiplier × hours
        $hourlyRate = (float) $employee->basic_salary / 30 / 8;
        $multiplier = (float) ($employee->overtime_multiplier ?: 1.5);
        return round($hourlyRate * $multiplier * $overtimeHours, 3);
    }

    /**
     * حساب خصم التأخير
     */
    private static function calculateLateDeduction(Employee $employee, int $month, int $year): float
    {
        $totalLateMinutes = Attendance::where('employee_id', $employee->id)
            ->whereMonth('date', $month)
            ->whereYear('date', $year)
            ->sum('late_minutes');

        if ($totalLateMinutes <= 0) return 0;

        // خصم التأخير = (الراتب الأساسي / 30 / 8 / 60) × دقائق التأخير
        $minuteRate = (float) $employee->basic_salary / 30 / 8 / 60;
        return round($minuteRate * $totalLateMinutes, 3);
    }

    /**
     * حساب خصم الغياب
     */
    private static function calculateAbsenceDeduction(Employee $employee, int $month, int $year): float
    {
        $absentDays = Attendance::where('employee_id', $employee->id)
            ->whereMonth('date', $month)
            ->whereYear('date', $year)
            ->where('status', 'absent')
            ->count();

        if ($absentDays <= 0) return 0;

        $dailyRate = (float) $employee->basic_salary / 30;
        return round($dailyRate * $absentDays, 3);
    }

    /**
     * حساب خصم الإجازات غير المدفوعة
     */
    private static function calculateUnpaidLeaveDeduction(Employee $employee, int $month, int $year): float
    {
        $startDate = Carbon::create($year, $month, 1);
        $endDate = $startDate->copy()->endOfMonth();

        $unpaidDays = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('is_paid', false)
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate]);
            })
            ->get()
            ->sum(function ($leave) use ($startDate, $endDate) {
                $leaveStart = Carbon::parse($leave->start_date)->max($startDate);
                $leaveEnd = Carbon::parse($leave->end_date)->min($endDate);
                return $leaveStart->diffInDays($leaveEnd) + 1;
            });

        if ($unpaidDays <= 0) return 0;

        $dailyRate = (float) $employee->basic_salary / 30;
        return round($dailyRate * $unpaidDays, 3);
    }

    /**
     * حساب خصم قسط السلفة
     */
    private static function calculateAdvanceDeduction(Employee $employee, int $month, int $year): float
    {
        return (float) AdvanceInstallment::where('employee_id', $employee->id)
            ->where('month', $month)
            ->where('year', $year)
            ->where('is_paid', false)
            ->sum('amount');
    }

    /**
     * حساب خصم المخالفات
     */
    private static function calculatePenaltyDeduction(Employee $employee, int $month, int $year): float
    {
        return 0;
    }

    /**
     * تحديث إجماليات المسير
     */
    private static function updatePayrollTotals(Payroll $payroll): void
    {
        $items = $payroll->items;

        $payroll->update([
            'total_basic' => $items->sum('basic_salary') + $items->sum('housing_allowance')
                + $items->sum('transport_allowance') + $items->sum('other_allowance')
                + $items->sum('overtime_amount') + $items->sum('bonus'),
            'total_allowances' => $items->sum('housing_allowance') + $items->sum('transport_allowance')
                + $items->sum('other_allowance'),
            'total_deductions' => $items->sum('total_deductions'),
            'total_net' => $items->sum('net_salary'),
            'employees_count' => $items->count(),
        ]);
    }

    /**
     * عند الاعتماد: تعليم أقساط السلف + المخالفات كمخصومة
     */
    public static function markDeductionsAsPaid(Payroll $payroll): void
    {
        foreach ($payroll->items as $item) {
            // تعليم أقساط السلفة كمدفوعة
            if ($item->advance_deduction > 0) {
                AdvanceInstallment::where('employee_id', $item->employee_id)
                    ->where('month', $payroll->month)
                    ->where('year', $payroll->year)
                    ->where('is_paid', false)
                    ->update(['is_paid' => true]);

                // تحديث remaining_amount في السلفة الأم
                $installments = AdvanceInstallment::where('employee_id', $item->employee_id)
                    ->whereHas('advance', fn($q) => $q->where('status', 'approved'))
                    ->where('is_paid', true)
                    ->get();

                foreach ($installments as $inst) {
                    $advance = $inst->advance;
                    if ($advance) {
                        $totalPaid = $advance->installments()->where('is_paid', true)->sum('amount');
                        $advance->update(['remaining_amount' => $advance->amount - $totalPaid]);
                    }
                }
            }
        }
    }
}
