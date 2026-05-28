<?php

namespace App\Observers;

use App\Models\Service;
use App\Models\Account;
use App\Services\AccountingSync;

class ServiceObserver
{
    public function saved(Service $service): void
    {
        if (AccountingSync::$isSyncing) return;
        AccountingSync::$isSyncing = true;

        try {
            $parentAccount = Account::where('code', '4001')->first();
            
            if ($parentAccount) {
                if (!$service->account_id) {
                    $newCode = AccountingSync::generateChildCode($parentAccount->id, $parentAccount->code);

                    $account = Account::create([
                        'code' => $newCode,
                        'name' => $service->name,
                        'parent_id' => $parentAccount->id,
                        'type' => 'revenue',
                        'is_active' => $service->is_active,
                        'currency' => 'JOD',
                    ]);

                    $service->update(['account_id' => $account->id]);
                } else {
                    Account::where('id', $service->account_id)->update([
                        'name' => $service->name,
                        'is_active' => $service->is_active,
                    ]);
                }
            }
        } finally {
            AccountingSync::$isSyncing = false;
        }
    }

    public function deleted(Service $service): void
    {
        if (AccountingSync::$isSyncing) return;
        AccountingSync::$isSyncing = true;

        try {
            if ($service->account_id) {
                Account::where('id', $service->account_id)->delete();
            }
        } finally {
            AccountingSync::$isSyncing = false;
        }
    }
}
