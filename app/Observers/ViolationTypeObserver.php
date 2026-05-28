<?php

namespace App\Observers;

use App\Models\ViolationType;
use App\Models\Account;
use App\Services\AccountingSync;

class ViolationTypeObserver
{
    public function saved(ViolationType $type): void
    {
        if (AccountingSync::$isSyncing) return;
        AccountingSync::$isSyncing = true;

        try {
            $parentAccount = Account::where('code', '5200')->first();
            
            if ($parentAccount) {
                if (!$type->account_id) {
                    $newCode = AccountingSync::generateChildCode($parentAccount->id, $parentAccount->code);

                    $account = Account::create([
                        'code' => $newCode,
                        'name' => $type->name,
                        'parent_id' => $parentAccount->id,
                        'type' => 'expense',
                        'is_active' => $type->is_active,
                        'currency' => 'JOD',
                    ]);

                    $type->update(['account_id' => $account->id]);
                } else {
                    Account::where('id', $type->account_id)->update([
                        'name' => $type->name,
                        'is_active' => $type->is_active,
                    ]);
                }
            }
        } finally {
            AccountingSync::$isSyncing = false;
        }
    }

    public function deleted(ViolationType $type): void
    {
        if (AccountingSync::$isSyncing) return;
        AccountingSync::$isSyncing = true;

        try {
            if ($type->account_id) {
                Account::where('id', $type->account_id)->delete();
            }
        } finally {
            AccountingSync::$isSyncing = false;
        }
    }
}
