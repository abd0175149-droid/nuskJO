<?php

namespace App\Observers;

use App\Models\Agent;
use App\Models\Account;
use App\Services\AccountingSync;
use Illuminate\Support\Facades\Log;

class AgentObserver
{
    public function saved(Agent $agent): void
    {
        if (AccountingSync::$isSyncing) return;
        AccountingSync::$isSyncing = true;

        try {
            $parentAccount = Account::where('code', '2110')->first();

            // إنشاء تلقائي إذا لم يوجد حساب 2110
            if (!$parentAccount) {
                Log::info('[AgentObserver] حساب 2110 غير موجود — إنشاء تلقائي');
                $creditors = Account::where('code', '2101')->first();
                if (!$creditors) {
                    $liabilities = Account::where('code', '2000')->first();
                    if ($liabilities) {
                        $creditors = Account::create([
                            'code' => '2101', 'name' => 'دائنون متنوعون',
                            'type' => 'liability', 'parent_id' => $liabilities->id,
                            'is_system' => true, 'is_active' => true, 'currency' => 'JOD', 'balance' => 0,
                        ]);
                    }
                }
                if ($creditors) {
                    $parentAccount = Account::create([
                        'code' => '2110', 'name' => 'الوكلاء',
                        'type' => 'liability', 'parent_id' => $creditors->id,
                        'is_system' => true, 'is_active' => true, 'currency' => 'JOD', 'balance' => 0,
                    ]);
                }
            }

            if ($parentAccount) {
                if (!$agent->account_id) {
                    $newCode = AccountingSync::generateChildCode($parentAccount->id, $parentAccount->code);

                    $account = Account::create([
                        'code' => $newCode,
                        'name' => $agent->name,
                        'parent_id' => $parentAccount->id,
                        'type' => 'liability',
                        'is_active' => $agent->is_active,
                        'currency' => 'JOD',
                    ]);

                    $agent->update(['account_id' => $account->id]);
                    Log::info("[AgentObserver] ✅ حساب {$newCode} للوكيل {$agent->name}");
                } else {
                    Account::where('id', $agent->account_id)->update([
                        'name' => $agent->name,
                        'is_active' => $agent->is_active,
                    ]);
                }
            } else {
                Log::error('[AgentObserver] فشل إنشاء حساب 2110!');
            }
        } catch (\Throwable $e) {
            Log::error("[AgentObserver] خطأ: {$e->getMessage()}");
        } finally {
            AccountingSync::$isSyncing = false;
        }
    }

    public function deleted(Agent $agent): void
    {
        if (AccountingSync::$isSyncing) return;
        AccountingSync::$isSyncing = true;

        try {
            if ($agent->account_id) {
                Account::where('id', $agent->account_id)->delete();
            }
        } finally {
            AccountingSync::$isSyncing = false;
        }
    }
}
