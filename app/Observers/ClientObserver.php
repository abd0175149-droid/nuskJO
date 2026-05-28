<?php

namespace App\Observers;

use App\Models\Client;
use App\Models\Account;
use App\Services\AccountingSync;

class ClientObserver
{
    public function saved(Client $client): void
    {
        if (AccountingSync::$isSyncing) return;
        AccountingSync::$isSyncing = true;

        try {
            $parentAccount = Account::where('code', '1200')->first();
            
            if ($parentAccount) {
                if (!$client->account_id) {
                    $newCode = AccountingSync::generateChildCode($parentAccount->id, $parentAccount->code);

                    $account = Account::create([
                        'code' => $newCode,
                        'name' => $client->name,
                        'parent_id' => $parentAccount->id,
                        'type' => 'asset',
                        'is_active' => $client->is_active,
                        'currency' => 'JOD',
                    ]);

                    $client->update(['account_id' => $account->id]);
                } else {
                    Account::where('id', $client->account_id)->update([
                        'name' => $client->name,
                        'is_active' => $client->is_active,
                    ]);
                }
            }
        } finally {
            AccountingSync::$isSyncing = false;
        }
    }

    public function deleted(Client $client): void
    {
        if (AccountingSync::$isSyncing) return;
        AccountingSync::$isSyncing = true;

        try {
            if ($client->account_id) {
                Account::where('id', $client->account_id)->delete();
            }
        } finally {
            AccountingSync::$isSyncing = false;
        }
    }
}
