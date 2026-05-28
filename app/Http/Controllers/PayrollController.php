<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\Employee;
use App\Services\PayrollService;
use App\Services\BankExportService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PayrollController extends Controller
{
    /**
     * قائمة المسيرات
     */
    public function index(Request $request)
    {
        if (!auth()->user()->can('payroll.view')) {
            abort(403);
        }

        $query = Payroll::with(['creator', 'approver']);

        if ($request->filled('month')) {
            $query->where('month', $request->month);
        }
        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }
        if ($request->filled('currency')) {
            $query->where('currency', $request->currency);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $payrolls = $query->orderBy('id', 'desc')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Payrolls/Index', [
            'payrolls' => $payrolls,
            'filters' => $request->only(['month', 'year', 'currency', 'status']),
        ]);
    }

    /**
     * توليد مسير رواتب
     */
    public function generate(Request $request)
    {
        if (!auth()->user()->can('payroll.generate')) {
            abort(403);
        }

        $validated = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2030',
            'currency' => 'required|string|in:SAR,JOD',
        ]);

        try {
            $payroll = PayrollService::generate(
                $validated['month'],
                $validated['year'],
                $validated['currency']
            );

            return redirect()->route('payrolls.show', $payroll)
                ->with('success', "تم توليد مسير الرواتب بنجاح — {$payroll->employees_count} موظف");
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * تفاصيل المسير
     */
    public function show(Payroll $payroll)
    {
        if (!auth()->user()->can('payroll.view')) {
            abort(403);
        }

        $payroll->load(['items.employee.user', 'items.employee.department', 'creator', 'approver']);

        return Inertia::render('Payrolls/Show', [
            'payroll' => $payroll,
        ]);
    }

    /**
     * اعتماد المسير
     */
    public function approve(Payroll $payroll)
    {
        if (!auth()->user()->can('payroll.approve')) {
            abort(403);
        }

        if (!$payroll->isPending()) {
            return redirect()->back()->withErrors(['error' => 'المسير ليس في حالة انتظار']);
        }

        DB::transaction(function () use ($payroll) {
            $payroll->approve(auth()->user());

            // تعليم الأقساط والمخالفات كمخصومة
            PayrollService::markDeductionsAsPaid($payroll);

            // تسجيل القيد المحاسبي للرواتب
            \App\Services\AccountingService::recordPayroll($payroll);

            // إشعار الموظفين
            NotificationService::payrollReady($payroll);
        });

        return redirect()->back()->with('success', 'تم اعتماد مسير الرواتب');
    }

    /**
     * تقديم المسير للاعتماد (من draft → pending)
     */
    public function submit(Payroll $payroll)
    {
        if (!auth()->user()->can('payroll.generate')) {
            abort(403);
        }

        if (!$payroll->isDraft()) {
            return redirect()->back()->withErrors(['error' => 'المسير ليس في حالة مسودة']);
        }

        $payroll->submit();

        NotificationService::notifyAdmins(
            '💰 مسير رواتب بانتظار الاعتماد',
            "مسير رواتب {$payroll->payroll_number} — {$payroll->period_label} ({$payroll->currency}) بمبلغ " . number_format((float) $payroll->total_net, 2),
            [
                'type' => 'payroll',
                'icon' => '💰',
                'action_url' => "/payrolls/{$payroll->id}",
            ]
        );

        return redirect()->back()->with('success', 'تم تقديم المسير للاعتماد');
    }

    /**
     * رفض المسير
     */
    public function reject(Request $request, Payroll $payroll)
    {
        if (!auth()->user()->can('payroll.reject')) {
            abort(403);
        }

        if (!$payroll->isPending()) {
            return redirect()->back()->withErrors(['error' => 'المسير ليس في حالة انتظار']);
        }

        $request->validate(['rejection_reason' => 'nullable|string|max:500']);

        $payroll->reject(auth()->user(), $request->rejection_reason ?? '');

        return redirect()->back()->with('success', 'تم رفض مسير الرواتب');
    }

    /**
     * طباعة المسير
     */
    public function print(Payroll $payroll)
    {
        if (!auth()->user()->can('payroll.view')) {
            abort(403);
        }

        $payroll->load(['items.employee.user', 'items.employee.department']);

        return Inertia::render('Payrolls/Print', [
            'payroll' => $payroll,
        ]);
    }

    /**
     * تصدير ملف بنكي
     */
    public function exportBank(Payroll $payroll)
    {
        if (!auth()->user()->can('payroll.view')) {
            abort(403);
        }

        if (!$payroll->isApproved()) {
            return redirect()->back()->withErrors(['error' => 'يجب اعتماد المسير أولاً']);
        }

        return BankExportService::exportCSV($payroll);
    }

    /**
     * قسيمة راتب موظف
     */
    public function payslip(Employee $employee, int $month, int $year)
    {
        $user = auth()->user();

        // الموظف يمكنه رؤية قسيمته فقط، المدير يمكنه رؤية الكل
        if ($user->employee && $user->employee->id !== $employee->id && !$user->can('payroll.view')) {
            abort(403);
        }

        $item = PayrollItem::with(['payroll', 'employee.user', 'employee.department'])
            ->where('employee_id', $employee->id)
            ->whereHas('payroll', function ($q) use ($month, $year) {
                $q->where('month', $month)->where('year', $year)->where('status', 'approved');
            })
            ->first();

        if (!$item) {
            return redirect()->back()->withErrors(['error' => 'لا توجد قسيمة راتب لهذه الفترة']);
        }

        return Inertia::render('HR/Payslip', [
            'item' => $item,
            'month' => $month,
            'year' => $year,
        ]);
    }
}
