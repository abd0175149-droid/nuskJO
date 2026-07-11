<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = ExpenseCategory::with('account:id,code,name')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

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
        // توليد كود فريد يشمل المحذوفة (soft-deleted) لتفادي تصادم قيد UNIQUE
        $nextNum = (int) ExpenseCategory::withTrashed()
            ->where('code', 'like', 'CAT-%')
            ->pluck('code')
            ->map(fn ($c) => (int) substr($c, 4))
            ->max() + 1;

        do {
            $code = 'CAT-' . str_pad($nextNum++, 3, '0', STR_PAD_LEFT);
        } while (ExpenseCategory::withTrashed()->where('code', $code)->exists());

        $validated['code'] = $code;
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
