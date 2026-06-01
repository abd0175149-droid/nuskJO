<?php

namespace App\Observers;

use App\Models\Account;
use App\Models\Agent;
use App\Models\Client;
use App\Models\Service;
use App\Models\ExpenseCategory;
use App\Services\AccountingSync;
use Illuminate\Support\Facades\Log;

class AccountObserver
{
    public function saved(Account $account): void
    {
        if (AccountingSync::$isSyncing) return;
        AccountingSync::$isSyncing = true;

        try {
            if (!$account->parent_id) return;
            
            $parent = Account::find($account->parent_id);
            if (!$parent) return;

            // تحقق إذا الأب أو الجد هو 2110 (الوكلاء)
            $isAgentAccount = $parent->code === '2110';
            if (!$isAgentAccount && $parent->parent_id) {
                $grandParent = Account::find($parent->parent_id);
                $isAgentAccount = $grandParent && $grandParent->code === '2110';
            }

            $entityData = [
                'name' => $account->name,
                'account_id' => $account->id,
                'is_active' => $account->is_active ?? true,
            ];

            if ($isAgentAccount) {
                // Agent
                $agent = Agent::where('account_id', $account->id)->first();
                if (!$agent) {
                    $lastCode = Agent::where('code', 'like', 'AGT-%')->orderByDesc('code')->value('code');
                    $nextNum = $lastCode ? (int)substr($lastCode, 4) + 1 : 1;
                    $entityData['code'] = 'AGT-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
                    $entityData['country'] = 'JO';
                    $entityData['currency'] = 'JOD';
                    $entityData['balance_sar'] = 0;
                    Agent::create($entityData);
                    Log::info("[AccountObserver] ✅ وكيل جديد من الشجرة: {$account->name}");
                } else {
                    $agent->update(['name' => $account->name, 'is_active' => $account->is_active]);
                }
            } elseif ($parent->code === '1200') {
                // Client
                $client = Client::where('account_id', $account->id)->first();
                if (!$client) {
                    $lastCode = Client::where('code', 'like', 'CLT-%')->orderByDesc('code')->value('code');
                    $nextNum = $lastCode ? (int)substr($lastCode, 4) + 1 : 1;
                    $entityData['code'] = 'CLT-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
                    $entityData['country'] = 'JO';
                    $entityData['currency'] = 'JOD';
                    $entityData['balance_jod'] = 0;
                    $entityData['credit_limit_jod'] = 0;
                    Client::create($entityData);
                } else {
                    $client->update(['name' => $account->name, 'is_active' => $account->is_active]);
                }
            } elseif ($parent->code === '4001') {
                // Service
                $service = Service::where('account_id', $account->id)->first();
                if (!$service) {
                    $lastCode = Service::where('code', 'like', 'SRV-%')->orderByDesc('code')->value('code');
                    $nextNum = $lastCode ? (int)substr($lastCode, 4) + 1 : 1;
                    $entityData['code'] = 'SRV-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
                    $entityData['default_price_sar'] = 0;
                    $entityData['default_price_jod'] = 0;
                    Service::create($entityData);
                } else {
                    $service->update(['name' => $account->name, 'is_active' => $account->is_active]);
                }
            } elseif ($parent->code === '5100') {
                // Expense Category
                $category = ExpenseCategory::where('account_id', $account->id)->first();
                if (!$category) {
                    $entityData['code'] = 'EXP-' . rand(100, 999);
                    ExpenseCategory::create($entityData);
                } else {
                    $category->update(['name' => $account->name, 'is_active' => $account->is_active]);
                }
                }
        } catch (\Throwable $e) {
            Log::error("[AccountObserver] خطأ: {$e->getMessage()}");
        } finally {
            AccountingSync::$isSyncing = false;
        }
    }

    public function deleted(Account $account): void
    {
        if (AccountingSync::$isSyncing) return;
        AccountingSync::$isSyncing = true;

        try {
            Agent::where('account_id', $account->id)->delete();
            Client::where('account_id', $account->id)->delete();
            Service::where('account_id', $account->id)->delete();
            ExpenseCategory::where('account_id', $account->id)->delete();
        } finally {
            AccountingSync::$isSyncing = false;
        }
    }
}
