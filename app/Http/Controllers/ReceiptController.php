<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Models\Client;
use App\Services\NumberingService;
use App\Services\BalanceService;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReceiptController extends Controller
{
    public function index(Request $request)
    {
        $receipts = Receipt::query()
            ->with(['client:id,name,code', 'creator:id,name', 'approver:id,name'])
            ->when($request->search, fn ($q, $s) => $q->where('receipt_number', 'like', "%{$s}%")
                ->orWhereHas('client', fn ($q2) => $q2->where('name', 'like', "%{$s}%")))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Receipts/Index', [
            'receipts' => $receipts,
            'filters' => $request->only(['search', 'status']),
            'clients' => Client::where('is_active', true)->select('id', 'name', 'code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'amount_jod' => 'required|numeric|min:0.001',
            'payment_method' => 'required|in:cash,bank,check',
            'bank_commission' => 'nullable|numeric|min:0',
            'check_number' => 'nullable|string|max:50',
            'check_date' => 'nullable|date',
            'check_bank' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
        ]);

        // العمولة فقط عند الدفع البنكي
        if ($validated['payment_method'] !== 'bank') {
            $validated['bank_commission'] = 0;
        } else {
            $validated['bank_commission'] = $validated['bank_commission'] ?? 0;
        }

        $validated['receipt_number'] = NumberingService::generate('REC');
        $validated['receipt_date'] = now()->toDateString();
        $validated['status'] = 'pending';
        $validated['created_by'] = auth()->id();

        $receipt = Receipt::create($validated);

        try { \App\Services\NotificationService::receiptCreated($receipt); } catch (\Exception $e) {}

        return redirect()->route('receipts.index')
            ->with('success', 'تم إنشاء سند القبض بنجاح');
    }

    public function approve(Receipt $receipt)
    {
        if (!$receipt->isPending()) {
            return back()->with('error', 'هذا السند ليس معلقاً');
        }

        DB::transaction(function () use ($receipt) {
            $receipt->approve(auth()->user());
            BalanceService::creditClient(
                $receipt->client,
                $receipt->amount_jod,
                'receipt',
                $receipt->id
            );
            // قيد محاسبي
            try { AccountingService::recordReceipt($receipt); } catch (\Exception $e) { \Log::error('Accounting Receipt: ' . $e->getMessage()); }
            try { \App\Services\NotificationService::receiptApproved($receipt); } catch (\Exception $e) {}
        });

        return back()->with('success', 'تم اعتماد سند القبض');
    }

    public function reject(Request $request, Receipt $receipt)
    {
        if (!$receipt->isPending()) {
            return back()->with('error', 'هذا السند ليس معلقاً');
        }

        $request->validate(['rejection_reason' => 'required|string|max:500']);
        $receipt->reject(auth()->user(), $request->rejection_reason);

        try { \App\Services\NotificationService::operationRejected($receipt, 'سند قبض', $receipt->receipt_number); } catch (\Exception $e) {}

        return back()->with('success', 'تم رفض سند القبض');
    }

    /**
     * بدء تعديل سند قبض معتمد
     */
    public function startEdit(Receipt $receipt)
    {
        if (!$receipt->isApproved()) {
            return back()->with('error', 'يمكن تعديل السندات المعتمدة فقط');
        }

        DB::transaction(function () use ($receipt) {
            // عكس تسديد ذمة العميل
            BalanceService::reverseClientCredit($receipt->client, $receipt->amount_jod, 'receipt', $receipt->id);
            // عكس القيد المحاسبي
            $entry = \App\Models\JournalEntry::where('reference_type', 'receipt')
                ->where('reference_id', $receipt->id)->where('is_reversed', false)->first();
            if ($entry) try { AccountingService::reverseEntry($entry, 'تعديل سند القبض'); } catch (\Exception $e) {}

            $receipt->startEditing(auth()->user());
            \App\Models\AuditLog::log('start_edit', 'receipt', $receipt->id, $receipt->receipt_number);
        });

        return back()->with('success', "تم فتح سند القبض {$receipt->receipt_number} للتعديل");
    }

    /**
     * تحديث سند قبض بعد الاعتماد
     */
    public function updateApproved(Request $request, Receipt $receipt)
    {
        if (!$receipt->isEditing()) {
            return back()->with('error', 'هذا السند ليس في وضع التعديل');
        }

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'amount_jod' => 'required|numeric|min:0.001',
            'payment_method' => 'required|in:cash,bank,check',
            'bank_commission' => 'nullable|numeric|min:0',
            'check_number' => 'nullable|string|max:50',
            'check_date' => 'nullable|date',
            'check_bank' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validated['payment_method'] !== 'bank') {
            $validated['bank_commission'] = 0;
        } else {
            $validated['bank_commission'] = $validated['bank_commission'] ?? 0;
        }

        $receipt->update($validated);
        $receipt->resubmitForApproval();

        return back()->with('success', "تم تعديل سند القبض وإرساله للاعتماد");
    }

    public function destroy(Receipt $receipt)
    {
        if (!$receipt->isPending()) {
            return back()->with('error', 'لا يمكن حذف سند معتمد');
        }
        $receipt->delete();
        return redirect()->route('receipts.index')->with('success', 'تم حذف سند القبض');
    }

    public function print(Receipt $receipt)
    {
        $receipt->load(['client:id,name,code', 'creator:id,name', 'approver:id,name']);

        $template = \App\Models\Setting::where('key', 'print_template_financial')->first();
        $templateUrl = $template?->value ? \Storage::url($template->value) : null;

        $layoutSetting = \App\Models\Setting::where('key', 'print_layout_receipt')->first();
        $layout = $layoutSetting?->value ? json_decode($layoutSetting->value, true) : null;

        return Inertia::render('Receipts/Print', [
            'receipt' => $receipt,
            'templateUrl' => $templateUrl,
            'layout' => $layout,
        ]);
    }
}
