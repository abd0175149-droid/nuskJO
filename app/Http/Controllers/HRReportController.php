<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\Advance;
use App\Models\AdvanceInstallment;

use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HRReportController extends Controller
{
    /**
     * تقرير الحضور
     */
    public function attendance(Request $request)
    {
        if (!auth()->user()->can('hr_reports.view')) abort(403);

        $month = $request->input('month', now()->month);
        $year = $request->input('year', now()->year);
        $employeeId = $request->input('employee_id');
        $departmentId = $request->input('department_id');

        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();

        $query = Attendance::with(['employee.user', 'employee.department'])
            ->whereBetween('date', [$startDate, $endDate]);

        if ($employeeId) $query->where('employee_id', $employeeId);
        if ($departmentId) $query->whereHas('employee', fn($q) => $q->where('department_id', $departmentId));

        $records = $query->orderBy('date', 'desc')->get();

        // إحصائيات
        $stats = [
            'total_records' => $records->count(),
            'present' => $records->where('status', 'present')->count(),
            'late' => $records->where('status', 'late')->count(),
            'absent' => $records->where('status', 'absent')->count(),
            'total_hours' => round($records->sum('total_hours'), 1),
            'total_overtime' => round($records->sum('overtime_minutes') / 60, 1),
            'total_late_minutes' => $records->sum('late_minutes'),
        ];

        // ملخص لكل موظف
        $employeeSummary = $records->groupBy('employee_id')->map(function ($items) {
            $emp = $items->first()->employee;
            return [
                'employee_id' => $emp->id,
                'name' => $emp->user->name ?? '',
                'employee_number' => $emp->employee_number,
                'department' => $emp->department->name ?? '—',
                'present_days' => $items->where('status', 'present')->count(),
                'late_days' => $items->where('status', 'late')->count(),
                'absent_days' => $items->where('status', 'absent')->count(),
                'total_hours' => round($items->sum('total_hours'), 1),
                'overtime_hours' => round($items->sum('overtime_minutes') / 60, 1),
                'late_minutes' => $items->sum('late_minutes'),
            ];
        })->values();

        $employees = Employee::active()->with('user')->get()->map(fn($e) => [
            'id' => $e->id, 'name' => $e->user->name ?? '', 'employee_number' => $e->employee_number,
        ]);

        $departments = \App\Models\Department::all(['id', 'name']);

        return Inertia::render('HR/Reports/AttendanceReport', [
            'records' => $records,
            'employeeSummary' => $employeeSummary,
            'stats' => $stats,
            'employees' => $employees,
            'departments' => $departments,
            'filters' => ['month' => (int) $month, 'year' => (int) $year, 'employee_id' => $employeeId, 'department_id' => $departmentId],
        ]);
    }

    /**
     * تقرير الرواتب
     */
    public function payrollSummary(Request $request)
    {
        if (!auth()->user()->can('hr_reports.view')) abort(403);

        $month = $request->input('month', now()->month);
        $year = $request->input('year', now()->year);

        $payrolls = Payroll::with(['items.employee.user', 'items.employee.department', 'creator'])
            ->where('month', $month)
            ->where('year', $year)
            ->orderBy('currency')
            ->get();

        return Inertia::render('HR/Reports/PayrollReport', [
            'payrolls' => $payrolls,
            'filters' => ['month' => (int) $month, 'year' => (int) $year],
        ]);
    }

    /**
     * كشف حساب موظف
     */
    public function employeeStatement(Employee $employee, Request $request)
    {
        if (!auth()->user()->can('hr_reports.view')) abort(403);

        $year = $request->input('year', now()->year);
        $employee->load(['user', 'department']);

        // سجل الرواتب
        $payrollItems = PayrollItem::with('payroll')
            ->where('employee_id', $employee->id)
            ->whereHas('payroll', fn($q) => $q->where('year', $year)->where('status', 'approved'))
            ->get()
            ->sortBy(fn($i) => $i->payroll->month);

        // السلف
        $advances = Advance::where('employee_id', $employee->id)
            ->whereYear('created_at', $year)
            ->with('installments')
            ->get();

        // المخالفات تمت إزالتها

        // الإجازات
        $leaves = LeaveRequest::where('employee_id', $employee->id)
            ->whereYear('start_date', $year)
            ->with('leaveType')
            ->get();

        return Inertia::render('HR/Reports/EmployeeStatement', [
            'employee' => $employee,
            'payrollItems' => $payrollItems,
            'advances' => $advances,
            'leaves' => $leaves,
            'filters' => ['year' => (int) $year],
        ]);
    }

    /**
     * بيانات ESS - حضوري
     */
    public function myAttendance(Request $request)
    {
        $user = auth()->user();
        $employee = $user->employee;
        if (!$employee) abort(404, 'لا يوجد ملف موظف مرتبط بحسابك');

        $month = $request->input('month', now()->month);
        $year = $request->input('year', now()->year);

        $startDate = Carbon::create($year, $month, 1);
        $endDate = $startDate->copy()->endOfMonth();

        $records = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'desc')
            ->get();

        $stats = [
            'present' => $records->where('status', 'present')->count(),
            'late' => $records->where('status', 'late')->count(),
            'absent' => $records->where('status', 'absent')->count(),
            'total_hours' => round($records->sum('total_hours'), 1),
            'overtime_hours' => round($records->sum('overtime_minutes') / 60, 1),
        ];

        return Inertia::render('HR/MyAttendance', [
            'records' => $records,
            'stats' => $stats,
            'employee' => $employee->load('user'),
            'filters' => ['month' => (int) $month, 'year' => (int) $year],
        ]);
    }

    /**
     * بيانات ESS - طلباتي
     */
    public function myRequests()
    {
        $user = auth()->user();
        $employee = $user->employee;
        if (!$employee) abort(404, 'لا يوجد ملف موظف مرتبط بحسابك');

        $leaves = LeaveRequest::where('employee_id', $employee->id)
            ->with('leaveType')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $advances = Advance::where('employee_id', $employee->id)
            ->with('installments')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $balances = \App\Models\LeaveBalance::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('year', now()->year)
            ->get();

        $leaveTypes = \App\Models\LeaveType::active()->forCountry($employee->country)->get();

        return Inertia::render('HR/MyRequests', [
            'leaves' => $leaves,
            'advances' => $advances,
            'balances' => $balances,
            'leaveTypes' => $leaveTypes,
            'employee' => $employee->load('user'),
        ]);
    }

    /**
     * عرض آخر قسيمة راتب للموظف
     */
    public function myPayslip()
    {
        $user = auth()->user();
        $employee = $user->employee;
        if (!$employee) abort(404, 'لا يوجد ملف موظف مرتبط بحسابك');

        $latestPayroll = \App\Models\Payroll::whereHas('items', function ($q) use ($employee) {
            $q->where('employee_id', $employee->id);
        })->where('status', 'approved')->orderByDesc('year')->orderByDesc('month')->first();

        if (!$latestPayroll) {
            return redirect()->back()->with('error', 'لا يوجد قسائم راتب معتمدة لك بعد.');
        }

        return redirect()->route('payslip', [
            'employee' => $employee->id,
            'month' => $latestPayroll->month,
            'year' => $latestPayroll->year
        ]);
    }
}
