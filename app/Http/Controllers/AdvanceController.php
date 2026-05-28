<?php

namespace App\Http\Controllers;

use App\Models\Advance;
use App\Models\AdvanceInstallment;
use App\Models\Employee;
use App\Services\NumberingService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AdvanceController extends Controller
{
    /**
     * قائمة السلف
     */
    public function index(Request $request)
    {
        if (!auth()->user()->can('advances.view')) {
            abort(403);
        }

        $query = Advance::with(['employee.user', 'creator', 'approver']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $advances = $query->orderBy('id', 'desc')
            ->paginate(15)
            ->withQueryString();

        $employees = Employee::with('user')->get()->map(fn($emp) => [
            'id' => $emp->id,
            'name' => $emp->user->name,
            'employee_number' => $emp->employee_number,
            'currency' => $emp->currency,
        ]);

        return Inertia::render('Advances/Index', [
            'advances' => $advances,
            'employees' => $employees,
            'filters' => $request->only(['employee_id', 'status']),
        ]);
    }

    /**
     * تقديم طلب سلفة
     */
    public function store(Request $request)
    {
        if (!auth()->user()->can('advances.create')) {
            abort(403);
        }

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'amount' => 'required|numeric|min:1',
            'installments_count' => 'required|integer|min:1|max:24',
            'reason' => 'nullable|string|max:500',
            'payment_method' => 'required|string|in:cash,bank',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        // التحقق من عدم وجود سلفة معلقة أو نشطة
        $activeAdvance = Advance::where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where('remaining_amount', '>', 0)
            ->exists();

        if ($activeAdvance) {
            return redirect()->back()->withErrors([
                'employee_id' => 'الموظف لديه سلفة نشطة أو معلقة. يجب سدادها أولاً.'
            ]);
        }

        $installmentAmount = round($validated['amount'] / $validated['installments_count'], 3);

        DB::transaction(function () use ($validated, $employee, $installmentAmount) {
            $advance = Advance::create([
                'advance_number' => NumberingService::generate('ADV'),
                'employee_id' => $validated['employee_id'],
                'amount' => $validated['amount'],
                'currency' => $employee->currency,
                'installments_count' => $validated['installments_count'],
                'installment_amount' => $installmentAmount,
                'remaining_amount' => $validated['amount'],
                'reason' => $validated['reason'] ?? null,
                'payment_method' => $validated['payment_method'],
                'status' => 'pending',
                'created_by' => auth()->id(),
            ]);

            NotificationService::advanceCreated($advance);
        });

        return redirect()->back()->with('success', 'تم تقديم طلب السلفة بنجاح');
    }

    /**
     * اعتماد سلفة
     */
    public function approve(Advance $advance)
    {
        if (!auth()->user()->can('advances.approve')) {
            abort(403);
        }

        if (!$advance->isPending()) {
            return redirect()->back()->withErrors(['error' => 'الطلب ليس في حالة انتظار']);
        }

        DB::transaction(function () use ($advance) {
            $advance->approve(auth()->user());

            // جدولة الأقساط (تبدأ من الشهر التالي)
            $startDate = Carbon::now()->addMonth()->startOfMonth();
            for ($i = 0; $i < $advance->installments_count; $i++) {
                $installmentDate = $startDate->copy()->addMonths($i);

                // القسط الأخير يحتوي على باقي المبلغ لتجنب فروقات التقريب
                $amount = ($i === $advance->installments_count - 1)
                    ? $advance->amount - ($advance->installment_amount * ($advance->installments_count - 1))
                    : $advance->installment_amount;

                AdvanceInstallment::create([
                    'advance_id' => $advance->id,
                    'employee_id' => $advance->employee_id,
                    'month' => $installmentDate->month,
                    'year' => $installmentDate->year,
                    'amount' => $amount,
                    'is_paid' => false,
                ]);
            }

            // إثبات السلفة محاسبياً
            \App\Services\AccountingService::recordAdvance($advance);

            NotificationService::advanceApproved($advance);
        });

        return redirect()->back()->with('success', 'تم اعتماد السلفة وجدولة الأقساط');
    }

    /**
     * رفض سلفة
     */
    public function reject(Request $request, Advance $advance)
    {
        if (!auth()->user()->can('advances.approve')) {
            abort(403);
        }

        if (!$advance->isPending()) {
            return redirect()->back()->withErrors(['error' => 'الطلب ليس في حالة انتظار']);
        }

        $request->validate(['rejection_reason' => 'nullable|string|max:500']);

        $advance->reject(auth()->user(), $request->rejection_reason ?? '');

        NotificationService::operationRejected($advance, 'طلب سلفة', $advance->advance_number);

        return redirect()->back()->with('success', 'تم رفض طلب السلفة');
    }

    /**
     * حذف سلفة (المعلقة فقط)
     */
    public function destroy(Advance $advance)
    {
        if (!auth()->user()->can('advances.delete')) {
            abort(403);
        }

        if (!$advance->isPending()) {
            return redirect()->back()->withErrors(['error' => 'لا يمكن حذف سلفة معتمدة أو مرفوضة']);
        }

        $advance->delete();

        return redirect()->back()->with('success', 'تم حذف طلب السلفة');
    }
}
