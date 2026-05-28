<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExpenseCategoryController extends Controller
{
    public function index()
    {
        $categories = ExpenseCategory::with('account:id,code,name')->orderBy('name')->get();

        // حسابات المصروفات من شجرة الحسابات (الورقية فقط — بدون الأب)
        $expenseAccounts = Account::where('type', 'expense')
            ->where('is_active', true)
            ->whereDoesntHave('children')
            ->select('id', 'code', 'name')
            ->orderBy('code')
            ->get();

        return Inertia::render('ExpenseCategories/Index', [
            'title' => 'تصنيفات المصاريف',
            'categories' => $categories,
            'expenseAccounts' => $expenseAccounts,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'account_id' => 'nullable|exists:accounts,id',
        ]);
        $lastCode = ExpenseCategory::where('code', 'like', 'CAT-%')->orderByDesc('code')->value('code');
        $nextNum = $lastCode ? (int)substr($lastCode, 4) + 1 : 1;
        $validated['code'] = 'CAT-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
        $validated['is_active'] = true;
        ExpenseCategory::create($validated);
        return back()->with('success', 'تم إضافة التصنيف');
    }

    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'account_id' => 'nullable|exists:accounts,id',
        ]);
        $expenseCategory->update($validated);
        return back()->with('success', 'تم تحديث التصنيف');
    }

    public function destroy(ExpenseCategory $expenseCategory)
    {
        if ($expenseCategory->expenses()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف تصنيف مرتبط بمصاريف');
        }
        $expenseCategory->delete();
        return back()->with('success', 'تم حذف التصنيف');
    }
}
