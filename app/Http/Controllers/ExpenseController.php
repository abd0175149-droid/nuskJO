<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\NumberingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $expenses = Expense::query()
            ->with(['category:id,name', 'creator:id,name', 'approver:id,name'])
            ->when($request->search, fn ($q, $s) => $q->where('expense_number', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%"))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'filters' => $request->only(['search', 'status']),
            'categories' => \App\Models\ExpenseCategory::select('id', 'name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:expense_categories,id',
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|in:SAR,JOD',
            'payment_method' => 'required|in:cash,bank,check',
            'expense_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $validated['expense_number'] = NumberingService::generate('EXP');
        $validated['expense_date'] = $validated['expense_date'] ?? now()->toDateString();
        $validated['status'] = 'pending';
        $validated['created_by'] = auth()->id();

        $expense = Expense::create($validated);

        try { \App\Services\NotificationService::expenseCreated($expense); } catch (\Exception $e) {}

        return redirect()->route('expenses.index')
            ->with('success', 'تم إنشاء المصروف بنجاح');
    }

    public function approve(Expense $expense)
    {
        if ($expense->status !== 'pending') {
            return back()->with('error', 'هذا المصروف ليس معلقاً');
        }

        $expense->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        // قيد محاسبي
        try { \App\Services\AccountingService::recordExpense($expense); } catch (\Exception $e) { \Log::error('Accounting Expense: ' . $e->getMessage()); }

        // إشعار
        try { \App\Services\NotificationService::expenseApproved($expense); } catch (\Exception $e) {}

        return back()->with('success', 'تم اعتماد المصروف');
    }

    public function reject(Request $request, Expense $expense)
    {
        if ($expense->status !== 'pending') {
            return back()->with('error', 'هذا المصروف ليس معلقاً');
        }

        $request->validate(['rejection_reason' => 'required|string|max:500']);
        $expense->update([
            'status' => 'rejected',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejection_reason' => $request->rejection_reason,
        ]);

        try { \App\Services\NotificationService::operationRejected($expense, 'مصروف', $expense->expense_number); } catch (\Exception $e) {}

        return back()->with('success', 'تم رفض المصروف');
    }

    /**
     * بدء تعديل مصروف معتمد
     */
    public function startEdit(Expense $expense)
    {
        if ($expense->status !== 'approved') {
            return back()->with('error', 'يمكن تعديل المصاريف المعتمدة فقط');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($expense) {
            // عكس القيد المحاسبي
            $entry = \App\Models\JournalEntry::where('reference_type', 'expense')
                ->where('reference_id', $expense->id)->where('is_reversed', false)->first();
            if ($entry) try { \App\Services\AccountingService::reverseEntry($entry, 'تعديل المصروف'); } catch (\Exception $e) {}

            $expense->update([
                'status' => 'editing',
                'modified_by' => auth()->id(),
                'modified_at' => now(),
                'original_values' => $expense->getOriginal(),
            ]);

            \App\Models\AuditLog::log('start_edit', 'expense', $expense->id, $expense->expense_number);
        });

        return back()->with('success', "تم فتح المصروف {$expense->expense_number} للتعديل");
    }

    /**
     * تحديث مصروف بعد الاعتماد
     */
    public function updateApproved(Request $request, Expense $expense)
    {
        if ($expense->status !== 'editing') {
            return back()->with('error', 'هذا المصروف ليس في وضع التعديل');
        }

        $validated = $request->validate([
            'category_id' => 'required|exists:expense_categories,id',
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|in:SAR,JOD',
            'payment_method' => 'required|in:cash,bank,check',
            'expense_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $expense->update($validated);
        $expense->update([
            'status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
            'rejection_reason' => null,
        ]);

        return back()->with('success', "تم تعديل المصروف وإرساله للاعتماد");
    }

    public function destroy(Expense $expense)
    {
        if ($expense->status !== 'pending') {
            return back()->with('error', 'لا يمكن حذف مصروف معتمد');
        }
        $expense->delete();
        return redirect()->route('expenses.index')->with('success', 'تم حذف المصروف');
    }

    public function print(Expense $expense)
    {
        $expense->load(['category:id,name', 'creator:id,name', 'approver:id,name']);

        return Inertia::render('Expenses/Print', [
            'expense' => $expense,
        ]);
    }
}
