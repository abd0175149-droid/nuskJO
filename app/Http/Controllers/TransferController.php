<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\Agent;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ExpenseCategory;
use App\Services\NumberingService;
use App\Services\BalanceService;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TransferController extends Controller
{
    private const EXCHANGE_RATE = 0.19; // سعر الصرف الثابت SAR→JOD

    public function index(Request $request)
    {
        $transfers = Transfer::query()
            ->with(['agent:id,name,code', 'creator:id,name', 'approver:id,name'])
            ->when($request->search, fn ($q, $s) => $q->where('transfer_number', 'like', "%{$s}%")
                ->orWhereHas('agent', fn ($q2) => $q2->where('name', 'like', "%{$s}%")))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->agent_id, fn ($q, $id) => $q->where('agent_id', $id))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        // حسابات الإيرادات للفرق
        $revenueAccounts = Account::where('type', 'revenue')
            ->where('is_active', true)
            ->whereDoesntHave('children')
            ->select('id', 'code', 'name')
            ->get();

        return Inertia::render('Transfers/Index', [
            'transfers' => $transfers,
            'filters' => $request->only(['search', 'status', 'agent_id']),
            'agents' => Agent::where('is_active', true)->select('id', 'name', 'code')->get(),
            'expenseCategories' => ExpenseCategory::where('is_active', true)->select('id', 'name')->get(),
            'revenueAccounts' => $revenueAccounts,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'agent_id' => 'required|exists:agents,id',
            'amount_sar' => 'required|numeric|min:0.01',
            'cost_jod' => 'required|numeric|min:0.001',
            'payment_method' => 'required|in:cash,bank,check',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'expense_category_id' => 'nullable|exists:expense_categories,id',
            'revenue_account_id' => 'nullable|exists:accounts,id',
        ]);

        // سعر صرف ثابت
        $validated['exchange_rate'] = self::EXCHANGE_RATE;
        $validated['transfer_number'] = NumberingService::generate('TRF');
        $validated['transfer_date'] = now()->toDateString();
        $validated['status'] = 'pending';
        $validated['created_by'] = auth()->id();

        // حساب الفرق بالدينار: cost_jod - (amount_sar * 0.19)
        $amountInJod = round((float)$validated['amount_sar'] * self::EXCHANGE_RATE, 3);
        $difference = round((float)$validated['cost_jod'] - $amountInJod, 3);

        $validated['difference_amount'] = $difference;
        if ($difference > 0) {
            $validated['difference_type'] = 'expense';
        } elseif ($difference < 0) {
            $validated['difference_type'] = 'revenue';
        } else {
            $validated['difference_type'] = null;
        }

        $transfer = Transfer::create($validated);

        try { \App\Services\NotificationService::transferCreated($transfer); } catch (\Exception $e) {}

        return redirect()->route('transfers.index')
            ->with('success', "تم إنشاء الحوالة {$transfer->transfer_number} بنجاح");
    }

    public function approve(Transfer $transfer)
    {
        if (!$transfer->isPending()) {
            return back()->with('error', 'هذه الحوالة ليست معلقة');
        }

        DB::transaction(function () use ($transfer) {
            $transfer->approve(auth()->user());
            BalanceService::creditAgent(
                $transfer->agent,
                $transfer->amount_sar,
                'transfer',
                $transfer->id
            );
            AuditLog::log('approve', 'transfer', $transfer->id, $transfer->transfer_number);
            // قيد محاسبي
            try { AccountingService::recordTransfer($transfer); } catch (\Exception $e) { \Log::error('Accounting Transfer: ' . $e->getMessage()); }
            // قيد الفرق (مصروف/إيراد)
            try { AccountingService::recordTransferDifference($transfer); } catch (\Exception $e) { \Log::error('Transfer Difference: ' . $e->getMessage()); }
            try { \App\Services\NotificationService::transferApproved($transfer); } catch (\Exception $e) {}
        });

        return back()->with('success', "تم اعتماد الحوالة {$transfer->transfer_number}");
    }

    public function reject(Request $request, Transfer $transfer)
    {
        if (!$transfer->isPending()) {
            return back()->with('error', 'هذه الحوالة ليست معلقة');
        }

        $request->validate(['rejection_reason' => 'required|string|max:500']);
        $transfer->reject(auth()->user(), $request->rejection_reason);
        AuditLog::log('reject', 'transfer', $transfer->id, $transfer->transfer_number);

        try { \App\Services\NotificationService::operationRejected($transfer, 'حوالة', $transfer->transfer_number); } catch (\Exception $e) {}

        return back()->with('success', "تم رفض الحوالة {$transfer->transfer_number}");
    }

    /**
     * بدء تعديل حوالة معتمدة
     */
    public function startEdit(Transfer $transfer)
    {
        if (!$transfer->isApproved()) {
            return back()->with('error', 'يمكن تعديل الحوالات المعتمدة فقط');
        }

        DB::transaction(function () use ($transfer) {
            // عكس الأثر المالي
            BalanceService::reverseAgentCredit(
                $transfer->agent,
                $transfer->amount_sar,
                'transfer',
                $transfer->id
            );
            // عكس القيد المحاسبي
            try { $this->reverseTransferAccounting($transfer); } catch (\Exception $e) { \Log::error('Reverse Transfer: ' . $e->getMessage()); }

            $transfer->startEditing(auth()->user());
            AuditLog::log('start_edit', 'transfer', $transfer->id, $transfer->transfer_number);
        });

        return back()->with('success', "تم فتح الحوالة {$transfer->transfer_number} للتعديل");
    }

    /**
     * تحديث حوالة بعد الاعتماد — تُعاد للاعتماد
     */
    public function updateApproved(Request $request, Transfer $transfer)
    {
        if (!$transfer->isEditing()) {
            return back()->with('error', 'هذه الحوالة ليست في وضع التعديل');
        }

        $validated = $request->validate([
            'agent_id' => 'required|exists:agents,id',
            'amount_sar' => 'required|numeric|min:0.01',
            'cost_jod' => 'required|numeric|min:0.001',
            'payment_method' => 'required|in:cash,bank,check',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'expense_category_id' => 'nullable|exists:expense_categories,id',
            'revenue_account_id' => 'nullable|exists:accounts,id',
        ]);

        // إعادة حساب الفرق
        $amountInJod = round((float)$validated['amount_sar'] * self::EXCHANGE_RATE, 3);
        $difference = round((float)$validated['cost_jod'] - $amountInJod, 3);
        $validated['difference_amount'] = $difference;
        $validated['difference_type'] = $difference > 0 ? 'expense' : ($difference < 0 ? 'revenue' : null);
        $validated['exchange_rate'] = self::EXCHANGE_RATE;

        $transfer->update($validated);
        $transfer->resubmitForApproval();

        try { \App\Services\NotificationService::transferCreated($transfer); } catch (\Exception $e) {}

        return back()->with('success', "تم تعديل الحوالة {$transfer->transfer_number} وإرسالها للاعتماد");
    }

    public function destroy(Transfer $transfer)
    {
        if (!$transfer->isPending()) {
            return back()->with('error', 'لا يمكن حذف حوالة معتمدة أو مرفوضة');
        }

        $transfer->delete();
        return redirect()->route('transfers.index')
            ->with('success', 'تم حذف الحوالة');
    }

    public function print(Transfer $transfer)
    {
        $transfer->load(['agent:id,name,code', 'creator:id,name', 'approver:id,name']);

        $template = \App\Models\Setting::where('key', 'print_template_financial')->first();
        $templateUrl = $template?->value ? \Storage::url($template->value) : null;

        $layoutSetting = \App\Models\Setting::where('key', 'print_layout_transfer')->first();
        $layout = $layoutSetting?->value ? json_decode($layoutSetting->value, true) : null;

        return Inertia::render('Transfers/Print', [
            'transfer' => $transfer,
            'templateUrl' => $templateUrl,
            'layout' => $layout,
        ]);
    }

    /**
     * عكس القيود المحاسبية للحوالة
     */
    private function reverseTransferAccounting(Transfer $transfer): void
    {
        // عكس قيد الحوالة الأصلي
        $entry = \App\Models\JournalEntry::where('reference_type', 'transfer')
            ->where('reference_id', $transfer->id)
            ->where('is_reversed', false)->first();
        if ($entry) AccountingService::reverseEntry($entry, 'تعديل الحوالة');

        // عكس قيد الفرق
        $diffEntry = \App\Models\JournalEntry::where('reference_type', 'transfer_difference')
            ->where('reference_id', $transfer->id)
            ->where('is_reversed', false)->first();
        if ($diffEntry) AccountingService::reverseEntry($diffEntry, 'تعديل فرق الحوالة');
    }
}
