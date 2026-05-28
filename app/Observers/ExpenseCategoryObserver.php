<?php

namespace App\Observers;

use App\Models\ExpenseCategory;
use App\Models\Account;
use App\Services\AccountingSync;

class ExpenseCategoryObserver
{
    public function saved(ExpenseCategory $category): void
    {
        if (AccountingSync::$isSyncing) return;
        AccountingSync::$isSyncing = true;

        try {
            $parentAccount = Account::where('code', '5100')->first();
            
            if ($parentAccount) {
                if (!$category->account_id) {
                    $newCode = AccountingSync::generateChildCode($parentAccount->id, $parentAccount->code);

                    $account = Account::create([
                        'code' => $newCode,
                        'name' => $category->name,
                        'parent_id' => $parentAccount->id,
                        'type' => 'expense',
                        'is_active' => $category->is_active,
                        'currency' => 'JOD',
                    ]);

                    $category->update(['account_id' => $account->id]);
                } else {
                    Account::where('id', $category->account_id)->update([
                        'name' => $category->name,
                        'is_active' => $category->is_active,
                    ]);
                }
            }
        } finally {
            AccountingSync::$isSyncing = false;
        }
    }

    public function deleted(ExpenseCategory $category): void
    {
        if (AccountingSync::$isSyncing) return;
        AccountingSync::$isSyncing = true;

        try {
            if ($category->account_id) {
                Account::where('id', $category->account_id)->delete();
            }
        } finally {
            AccountingSync::$isSyncing = false;
        }
    }
}
