<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\Employee;
use App\Services\NumberingService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LeaveController extends Controller
{
    /**
     * قائمة الطلبات (المدير: الكل، الموظف: طلباته)
     */
    public function index(Request $request)
    {
        if (!auth()->user()->can('leaves.view')) {
            abort(403);
        }

        $query = LeaveRequest::with(['employee.user', 'leaveType', 'creator', 'approver']);

        // فلترة بالموظف
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        // فلترة بنوع الإجازة
        if ($request->filled('leave_type_id')) {
            $query->where('leave_type_id', $request->leave_type_id);
        }

        // فلترة بالحالة
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $leaveRequests = $query->orderBy('id', 'desc')
            ->paginate(15)
            ->withQueryString();

        $employees = Employee::with('user')->get()->map(fn($emp) => [
            'id' => $emp->id,
            'name' => $emp->user->name,
            'employee_number' => $emp->employee_number,
        ]);

        $leaveTypes = LeaveType::active()->get();

        return Inertia::render('Leaves/Index', [
            'leaveRequests' => $leaveRequests,
            'employees' => $employees,
            'leaveTypes' => $leaveTypes,
            'filters' => $request->only(['employee_id', 'leave_type_id', 'status']),
        ]);
    }

    /**
     * تقديم طلب إجازة
     */
    public function store(Request $request)
    {
        if (!auth()->user()->can('leaves.create')) {
            abort(403);
        }

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:500',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $leaveType = LeaveType::findOrFail($validated['leave_type_id']);

        // حساب عدد الأيام
        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);
        $daysCount = $startDate->diffInDays($endDate) + 1;

        // التحقق من max_consecutive_days
        if ($leaveType->max_consecutive_days && $daysCount > $leaveType->max_consecutive_days) {
            return redirect()->back()->withErrors([
                'end_date' => "الحد الأقصى للأيام المتتالية لهذا النوع هو {$leaveType->max_consecutive_days} يوم."
            ]);
        }

        // التحقق من الرصيد (للإجازات المدفوعة فقط)
        if ($leaveType->is_paid) {
            $balance = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $leaveType->id)
                ->where('year', now()->year)
                ->first();

            if (!$balance || $balance->remaining_days < $daysCount) {
                $remaining = $balance ? $balance->remaining_days : 0;
                return redirect()->back()->withErrors([
                    'leave_type_id' => "الرصيد المتبقي ({$remaining} يوم) غير كافٍ لعدد الأيام المطلوبة ({$daysCount} يوم)."
                ]);
            }
        }

        // التحقق من عدم وجود تداخل مع إجازة أخرى
        $overlap = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($q2) use ($startDate, $endDate) {
                        $q2->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            })->exists();

        if ($overlap) {
            return redirect()->back()->withErrors([
                'start_date' => 'يوجد تداخل مع طلب إجازة آخر في هذه الفترة.'
            ]);
        }

        DB::transaction(function () use ($validated, $daysCount, $leaveType) {
            $leaveRequest = LeaveRequest::create([
                'request_number' => NumberingService::generate('LRQ'),
                'employee_id' => $validated['employee_id'],
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'days_count' => $daysCount,
                'is_paid' => $leaveType->is_paid,
                'reason' => $validated['reason'] ?? null,
                'status' => 'pending',
                'created_by' => auth()->id(),
            ]);

            NotificationService::leaveRequestCreated($leaveRequest);
        });

        return redirect()->back()->with('success', 'تم تقديم طلب الإجازة بنجاح');
    }

    /**
     * اعتماد طلب إجازة
     */
    public function approve(LeaveRequest $leave)
    {
        if (!auth()->user()->can('leaves.approve')) {
            abort(403);
        }

        if (!$leave->isPending()) {
            return redirect()->back()->withErrors(['error' => 'الطلب ليس في حالة انتظار']);
        }

        DB::transaction(function () use ($leave) {
            $leave->approve(auth()->user());

            // خصم الرصيد
            if ($leave->is_paid) {
                $balance = LeaveBalance::where('employee_id', $leave->employee_id)
                    ->where('leave_type_id', $leave->leave_type_id)
                    ->where('year', Carbon::parse($leave->start_date)->year)
                    ->first();

                if ($balance) {
                    $balance->update([
                        'used_days' => $balance->used_days + $leave->days_count,
                        'remaining_days' => $balance->remaining_days - $leave->days_count,
                    ]);
                }
            }

            NotificationService::leaveApproved($leave);
        });

        return redirect()->back()->with('success', 'تم اعتماد طلب الإجازة');
    }

    /**
     * رفض طلب إجازة
     */
    public function reject(Request $request, LeaveRequest $leave)
    {
        if (!auth()->user()->can('leaves.approve')) {
            abort(403);
        }

        if (!$leave->isPending()) {
            return redirect()->back()->withErrors(['error' => 'الطلب ليس في حالة انتظار']);
        }

        $request->validate(['rejection_reason' => 'nullable|string|max:500']);

        $leave->reject(auth()->user(), $request->rejection_reason ?? '');

        NotificationService::operationRejected($leave, 'طلب إجازة', $leave->request_number);

        return redirect()->back()->with('success', 'تم رفض طلب الإجازة');
    }

    /**
     * حذف طلب إجازة (المعلقة فقط)
     */
    public function destroy(LeaveRequest $leave)
    {
        if (!auth()->user()->can('leaves.delete')) {
            abort(403);
        }

        if (!$leave->isPending()) {
            return redirect()->back()->withErrors(['error' => 'لا يمكن حذف طلب معتمد أو مرفوض']);
        }

        $leave->delete();

        return redirect()->back()->with('success', 'تم حذف طلب الإجازة');
    }

    /**
     * طلبات الموظف الحالي (ESS)
     */
    public function myRequests()
    {
        $user = auth()->user();
        $employee = $user->employee;

        if (!$employee) {
            return Inertia::render('HR/MyRequests', [
                'leaveRequests' => [],
                'balances' => [],
                'leaveTypes' => [],
            ]);
        }

        $leaveRequests = LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id)
            ->orderBy('id', 'desc')
            ->paginate(15);

        $balances = LeaveBalance::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('year', now()->year)
            ->get();

        $leaveTypes = LeaveType::active()->forCountry($employee->country)->get();

        return Inertia::render('HR/MyRequests', [
            'leaveRequests' => $leaveRequests,
            'balances' => $balances,
            'leaveTypes' => $leaveTypes,
            'employee' => $employee,
        ]);
    }
}
