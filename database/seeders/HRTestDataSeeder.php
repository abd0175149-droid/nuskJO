<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Department;
use App\Models\Shift;
use App\Models\User;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\LeaveRequest;
use App\Models\Advance;
use App\Models\AdvanceInstallment;
use App\Models\EmployeePenalty;
use App\Models\Attendance;
use App\Services\NumberingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class HRTestDataSeeder extends Seeder
{
    public function run(): void
    {
        // تسجيل دخول وهمي لأن PayrollService يستخدم auth()->id()
        $admin = User::first();
        if ($admin) {
            auth()->login($admin);
        }

        DB::transaction(function () use ($admin) {
            // 1. الأقسام (Departments)
            $itDept = Department::firstOrCreate(['name' => 'تقنية المعلومات'], ['code' => 'IT']);
            $salesDept = Department::firstOrCreate(['name' => 'المبيعات'], ['code' => 'SALES']);

            // 2. الورديات (Shifts) — الحقول من Shift model: name, code, start_time, end_time, grace_minutes, working_days, country, break_minutes, is_flexible, min_hours, is_active
            $morningShift = Shift::firstOrCreate(
                ['name' => 'الوردية الصباحية'],
                [
                    'code' => 'MORN',
                    'start_time' => '09:00:00',
                    'end_time' => '17:00:00',
                    'grace_minutes' => 15,
                    'working_days' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'],
                    'break_minutes' => 60,
                    'is_flexible' => false,
                    'min_hours' => 8,
                    'country' => 'SA',
                ]
            );

            // 3. إنشاء مستخدم وموظف تجريبي — الحقول من Employee model: employee_number, user_id, department_id, shift_id, job_title, hire_date, contract_type, country, currency, basic_salary, housing_allowance, transport_allowance, other_allowance, overtime_calc_method, overtime_multiplier, is_active
            $salesRole = \App\Models\Role::firstOrCreate(['slug' => 'sales'], ['name' => 'موظف مبيعات']);
            $user1 = User::firstOrCreate(
                ['email' => 'emp1@nusuk.test'],
                [
                    'name' => 'أحمد الموظف',
                    'password' => Hash::make('password'),
                    'role_id' => $salesRole->id,
                ]
            );

            $employee1 = Employee::firstOrCreate(
                ['user_id' => $user1->id],
                [
                    'employee_number' => NumberingService::generate('EMP'),
                    'department_id' => $salesDept->id,
                    'shift_id' => $morningShift->id,
                    'job_title' => 'موظف مبيعات',
                    'hire_date' => Carbon::now()->subMonths(6)->toDateString(),
                    'contract_type' => 'full_time',
                    'country' => 'SA',
                    'currency' => 'SAR',
                    'basic_salary' => 4000,
                    'housing_allowance' => 1000,
                    'transport_allowance' => 500,
                    'other_allowance' => 0,
                    'overtime_calc_method' => 'multiplier',
                    'overtime_multiplier' => 1.5,
                    'is_active' => true,
                ]
            );

            // 4. الحضور والانصراف — الحقول من Attendance model: employee_id, date, check_in (datetime), check_out (datetime), worked_hours, late_minutes, overtime_minutes, status, notes
            $today = Carbon::today();
            for ($i = 1; $i <= 5; $i++) {
                $date = $today->copy()->subDays($i);
                if (!in_array($date->format('l'), $morningShift->working_days ?? [])) continue;

                Attendance::firstOrCreate([
                    'employee_id' => $employee1->id,
                    'date' => $date->toDateString(),
                ], [
                    'check_in' => $date->copy()->setTime(9, 5, 0),
                    'check_out' => $date->copy()->setTime(17, 30, 0),
                    'status' => 'present',
                    'late_minutes' => 0,
                    'overtime_minutes' => 30,
                    'worked_hours' => 8.42,
                ]);
            }

            // 5. أنواع الإجازات — الحقول من LeaveType model: name, code, country, default_days, is_paid, is_active, requires_attachment, max_consecutive_days, description
            $annualLeave = LeaveType::firstOrCreate(['code' => 'ANNUAL'], [
                'name' => 'إجازة سنوية',
                'country' => 'SA',
                'default_days' => 21,
                'is_paid' => true,
                'is_active' => true,
            ]);

            // طلب إجازة — الحقول من LeaveRequest model: request_number, employee_id, leave_type_id, start_date, end_date, days_count, is_paid, reason, status, created_by, approved_by, approved_at
            $leaveReq = LeaveRequest::firstOrCreate([
                'employee_id' => $employee1->id,
                'leave_type_id' => $annualLeave->id,
                'start_date' => $today->copy()->addDays(2)->toDateString(),
                'end_date' => $today->copy()->addDays(3)->toDateString(),
            ], [
                'request_number' => NumberingService::generate('LV'),
                'days_count' => 2,
                'is_paid' => true,
                'reason' => 'ظرف عائلي',
                'status' => 'pending',
                'created_by' => $user1->id,
            ]);
            if ($leaveReq->isPending()) {
                $leaveReq->approve($admin ?? $user1);
            }

            // 6. طلب سلفة — الحقول من Advance model: advance_number, employee_id, amount, currency, installments_count, installment_amount, remaining_amount, reason, payment_method, status, created_by
            $advance = Advance::firstOrCreate([
                'employee_id' => $employee1->id,
                'amount' => 1000,
                'installments_count' => 2,
            ], [
                'advance_number' => NumberingService::generate('ADV'),
                'currency' => 'SAR',
                'installment_amount' => 500,
                'remaining_amount' => 1000,
                'payment_method' => 'cash',
                'reason' => 'سلفة طارئة',
                'status' => 'pending',
                'created_by' => $admin->id ?? $user1->id,
            ]);

            if ($advance->isPending()) {
                $advance->approve($admin ?? $user1);

                // جدولة الأقساط
                $startDate = Carbon::now()->addMonth()->startOfMonth();
                for ($i = 0; $i < $advance->installments_count; $i++) {
                    $installmentDate = $startDate->copy()->addMonths($i);
                    $amount = ($i === $advance->installments_count - 1)
                        ? $advance->amount - ($advance->installment_amount * ($advance->installments_count - 1))
                        : $advance->installment_amount;

                    AdvanceInstallment::firstOrCreate([
                        'advance_id' => $advance->id,
                        'month' => $installmentDate->month,
                        'year' => $installmentDate->year,
                    ], [
                        'employee_id' => $advance->employee_id,
                        'amount' => $amount,
                        'is_paid' => false,
                    ]);
                }

                // تسجيل القيد المحاسبي
                try {
                    \App\Services\AccountingService::recordAdvance($advance);
                } catch (\Exception $e) {
                    // تجاهل إذا سبق التسجيل
                }
            }

            // 7. المخالفات — الحقول من EmployeePenalty model: penalty_number, employee_id, penalty_type, deduction_days, deduction_amount, penalty_date, reason, is_deducted, created_by
            EmployeePenalty::firstOrCreate([
                'employee_id' => $employee1->id,
                'penalty_date' => $today->toDateString(),
                'penalty_type' => 'deduction',
            ], [
                'penalty_number' => NumberingService::generate('PEN'),
                'deduction_amount' => 100,
                'reason' => 'تأخير متكرر رغم التنبيه',
                'is_deducted' => false,
                'created_by' => $admin->id ?? $user1->id,
            ]);

            // 8. مسير رواتب تجريبي
            try {
                $payroll = \App\Services\PayrollService::generate($today->month, $today->year, 'SAR');
                \App\Services\AccountingService::recordPayroll($payroll);
            } catch (\Exception $e) {
                // إذا كان المسير موجود مسبقاً نتجاهل
            }
        });
    }
}
