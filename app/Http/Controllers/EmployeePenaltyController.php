<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeePenalty;
use App\Services\NumberingService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeePenaltyController extends Controller
{
    /**
     * قائمة المخالفات الداخلية
     */
    public function index(Request $request)
    {
        if (!auth()->user()->can('penalties.view')) {
            abort(403);
        }

        $query = EmployeePenalty::with(['employee.user', 'creator']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('penalty_type')) {
            $query->where('penalty_type', $request->penalty_type);
        }

        $penalties = $query->orderBy('id', 'desc')
            ->paginate(15)
            ->withQueryString();

        $employees = Employee::with('user')->get()->map(fn($emp) => [
            'id' => $emp->id,
            'name' => $emp->user->name,
            'employee_number' => $emp->employee_number,
        ]);

        return Inertia::render('EmployeePenalties/Index', [
            'penalties' => $penalties,
            'employees' => $employees,
            'filters' => $request->only(['employee_id', 'penalty_type']),
        ]);
    }

    /**
     * إصدار مخالفة
     */
    public function store(Request $request)
    {
        if (!auth()->user()->can('penalties.create')) {
            abort(403);
        }

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'penalty_type' => 'required|in:warning,deduction',
            'deduction_days' => 'nullable|required_if:penalty_type,deduction|integer|min:0',
            'deduction_amount' => 'nullable|required_if:penalty_type,deduction|numeric|min:0',
            'penalty_date' => 'required|date',
            'reason' => 'required|string|max:500',
        ]);

        $penalty = EmployeePenalty::create([
            'penalty_number' => NumberingService::generate('PEN'),
            'employee_id' => $validated['employee_id'],
            'penalty_type' => $validated['penalty_type'],
            'deduction_days' => $validated['deduction_days'] ?? 0,
            'deduction_amount' => $validated['deduction_amount'] ?? 0,
            'penalty_date' => $validated['penalty_date'],
            'reason' => $validated['reason'],
            'is_deducted' => false,
            'created_by' => auth()->id(),
        ]);

        // إشعار الموظف
        NotificationService::penaltyIssued($penalty);

        return redirect()->back()->with('success', 'تم إصدار المخالفة بنجاح');
    }

    /**
     * حذف مخالفة
     */
    public function destroy(EmployeePenalty $penalty)
    {
        if (!auth()->user()->can('penalties.delete')) {
            abort(403);
        }

        if ($penalty->is_deducted) {
            return redirect()->back()->withErrors(['error' => 'لا يمكن حذف مخالفة تم خصمها من الراتب']);
        }

        $penalty->delete();

        return redirect()->back()->with('success', 'تم حذف المخالفة');
    }
}
