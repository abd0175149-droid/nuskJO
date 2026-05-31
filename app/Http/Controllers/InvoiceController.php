<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Agent;
use App\Models\Client;
use App\Models\Service;
use App\Models\Violation;
use App\Models\ExchangeRate;
use App\Models\AuditLog;
use App\Services\BalanceService;
use App\Services\NumberingService;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $invoices = Invoice::query()
            ->with(['agent:id,name,code', 'client:id,name,code', 'creator:id,name', 'approver:id,name'])
            ->when($request->search, fn ($q, $s) => $q->where('invoice_number', 'like', "%{$s}%")
                ->orWhereHas('agent', fn ($q2) => $q2->where('name', 'like', "%{$s}%"))
                ->orWhereHas('client', fn ($q2) => $q2->where('name', 'like', "%{$s}%")))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Invoices/Index', [
            'title' => 'الفواتير',
            'invoices' => $invoices,
            'filters' => $request->only(['search', 'status']),
            'agents' => Agent::where('is_active', true)->select('id', 'name', 'code', 'currency')->get(),
            'clients' => Client::where('is_active', true)->select('id', 'name', 'code')->get(),
            'services' => Service::where('is_active', true)->get(),
            'exchangeRate' => 0.19, // سعر صرف ثابت SAR→JOD
        ]);
    }

    public function print(Invoice $invoice)
    {
        $invoice->load(['agent:id,name,code', 'client:id,name,code', 'items']);

        $template = \App\Models\Setting::where('key', 'print_template_financial')->first();
        $templateUrl = $template?->value ? \Storage::url($template->value) : null;

        $layoutSetting = \App\Models\Setting::where('key', 'print_layout_invoice')->first();
        $layout = $layoutSetting?->value ? json_decode($layoutSetting->value, true) : null;

        return Inertia::render('Invoices/Print', [
            'invoice' => $invoice,
            'templateUrl' => $templateUrl,
            'layout' => $layout,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'agent_id' => 'required|exists:agents,id',
            'client_id' => 'required|exists:clients,id',
            'exchange_rate_snapshot' => 'required|numeric|min:0.001',
            'discount_sar' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.item_type' => 'required|in:service,violation',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price_sar' => 'required|numeric|min:0',
            'items.*.sell_price_jod' => 'required|numeric|min:0',
            'items.*.service_id' => 'nullable|integer',
            'items.*.violation_id' => 'nullable|integer',
        ]);

        DB::transaction(function () use ($validated) {
            $rate = $validated['exchange_rate_snapshot'];
            $discount = $validated['discount_sar'] ?? 0;

            // حساب الإجماليات
            $servicesCost = 0;
            $violationsCost = 0;
            $subtotal = 0;
            $totalSellJod = 0;

            foreach ($validated['items'] as $item) {
                $lineTotal = $item['quantity'] * $item['unit_price_sar'];
                $lineSellJod = $item['quantity'] * $item['sell_price_jod'];
                $subtotal += $lineTotal;
                $totalSellJod += $lineSellJod;
                if ($item['item_type'] === 'service') {
                    $servicesCost += $lineTotal;
                } else {
                    $violationsCost += $lineTotal;
                }
            }

            $totalSar = $subtotal - $discount;
            // إجمالي العميل بالدينار (مجموع سعر البيع × الكمية)
            $totalJod = round($totalSellJod, 3);
            // تكلفة الوكيل بالدينار
            $agentCostJod = round($totalSar * $rate, 3);
            // الربح = إجمالي العميل - تكلفة الوكيل
            $profitJod = round($totalJod - $agentCostJod, 3);
            $profitSar = $rate > 0 ? round($profitJod / $rate, 2) : 0;

            $invoice = Invoice::create([
                'invoice_number' => NumberingService::generate('INV'),
                'agent_id' => $validated['agent_id'],
                'client_id' => $validated['client_id'],
                'exchange_rate_snapshot' => $rate,
                'subtotal_sar' => $subtotal,
                'discount_sar' => $discount,
                'total_sar' => $totalSar,
                'total_jod' => $totalJod,
                'services_cost_sar' => $servicesCost,
                'violations_cost_sar' => $violationsCost,
                'profit_sar' => $profitSar,
                'profit_jod' => $profitJod,
                'invoice_date' => now()->toDateString(),
                'status' => 'pending',
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // حفظ البنود
            foreach ($validated['items'] as $i => $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'item_type' => $item['item_type'],
                    'service_id' => $item['service_id'] ?? null,
                    'violation_id' => $item['violation_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price_sar' => $item['unit_price_sar'],
                    'sell_price_jod' => $item['sell_price_jod'],
                    'total_cost_sar' => $item['quantity'] * $item['unit_price_sar'],
                    'total_sell_jod' => $item['quantity'] * $item['sell_price_jod'],
                    'sort_order' => $i + 1,
                ]);
            }

            try { \App\Services\NotificationService::invoiceCreated($invoice); } catch (\Exception $e) {}
        });

        return redirect()->back()->with('success', 'تم إنشاء الفاتورة بنجاح');
    }

    public function approve(Invoice $invoice)
    {
        if (!$invoice->isPending()) {
            return back()->with('error', 'هذه الفاتورة ليست معلقة');
        }

        DB::transaction(function () use ($invoice) {
            $invoice->update([
                'status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            // خصم تكلفة الخدمات من الوكيل (المخالفات خُصمت سابقاً)
            if ($invoice->services_cost_sar > 0) {
                BalanceService::debitAgent(
                    $invoice->agent,
                    $invoice->services_cost_sar,
                    'invoice',
                    $invoice->id
                );
            }

            // إضافة الإجمالي على العميل بالدينار
            if ($invoice->total_jod > 0) {
                BalanceService::debitClient(
                    $invoice->client,
                    $invoice->total_jod,
                    'invoice',
                    $invoice->id
                );
            }

            // إغلاق المخالفات المضمنة + عكس قيودها المحاسبية
            $violationIds = $invoice->violationItems()->pluck('violation_id')->filter();
            if ($violationIds->count()) {
                Violation::whereIn('id', $violationIds)->update([
                    'billing_status' => 'billed',
                    'invoice_id' => $invoice->id,
                ]);

                // عكس قيود مصاريف المخالفات (لم تعد مصاريف مستقلة)
                $violations = Violation::whereIn('id', $violationIds)->get();
                foreach ($violations as $violation) {
                    try {
                        AccountingService::reverseViolationExpense($violation);
                    } catch (\Exception $e) {
                        \Log::error("Reverse Violation Expense #{$violation->id}: " . $e->getMessage());
                    }
                }
            }

            // قيد محاسبي
            try { AccountingService::recordInvoice($invoice); } catch (\Exception $e) { \Log::error('Accounting Invoice: ' . $e->getMessage()); }

            // إشعار لصانع الفاتورة أنه تم الاعتماد
            if ($invoice->created_by && $invoice->created_by !== auth()->id()) {
                try { \App\Services\NotificationService::send($invoice->created_by, '✅ تم اعتماد فاتورتك', "تم اعتماد الفاتورة {$invoice->invoice_number}", ['type' => 'invoice', 'icon' => '✅', 'action_url' => '/invoices']); } catch (\Exception $e) {}
            }
        });

        AuditLog::log('approve', 'invoice', $invoice->id, $invoice->invoice_number);
        return back()->with('success', "تم اعتماد الفاتورة {$invoice->invoice_number}");
    }

    public function reject(Request $request, Invoice $invoice)
    {
        if (!$invoice->isPending()) {
            return back()->with('error', 'هذه الفاتورة ليست معلقة');
        }
        $invoice->update([
            'status' => 'rejected',
            'rejection_reason' => $request->input('reason', ''),
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        try { \App\Services\NotificationService::operationRejected($invoice, 'فاتورة', $invoice->invoice_number); } catch (\Exception $e) {}

        return back()->with('success', 'تم رفض الفاتورة');
    }

    /**
     * بدء تعديل فاتورة معتمدة
     */
    public function startEdit(Invoice $invoice)
    {
        if (!$invoice->isApproved()) {
            return back()->with('error', 'يمكن تعديل الفواتير المعتمدة فقط');
        }

        DB::transaction(function () use ($invoice) {
            // عكس خصم الوكيل (الخدمات)
            if ($invoice->services_cost_sar > 0) {
                BalanceService::reverseAgentDebit($invoice->agent, $invoice->services_cost_sar, 'invoice', $invoice->id);
            }
            // عكس ذمة العميل
            if ($invoice->total_jod > 0) {
                BalanceService::reverseClientDebit($invoice->client, $invoice->total_jod, 'invoice', $invoice->id);
            }
            // عكس القيد المحاسبي
            $entry = \App\Models\JournalEntry::where('reference_type', 'invoice')
                ->where('reference_id', $invoice->id)->where('is_reversed', false)->first();
            if ($entry) try { AccountingService::reverseEntry($entry, 'تعديل الفاتورة'); } catch (\Exception $e) {}

            // إعادة المخالفات لحالة unbilled
            Violation::where('invoice_id', $invoice->id)->update([
                'billing_status' => 'unbilled', 'invoice_id' => null
            ]);

            $invoice->startEditing(auth()->user());
            AuditLog::log('start_edit', 'invoice', $invoice->id, $invoice->invoice_number);
        });

        return back()->with('success', "تم فتح الفاتورة {$invoice->invoice_number} للتعديل");
    }

    /**
     * تحديث فاتورة في حالة التعديل
     */
    public function update(Request $request, Invoice $invoice)
    {
        if ($invoice->status !== 'editing' && $invoice->status !== 'pending') {
            return back()->with('error', 'لا يمكن تعديل هذه الفاتورة');
        }

        $validated = $request->validate([
            'agent_id' => 'required|exists:agents,id',
            'client_id' => 'required|exists:clients,id',
            'exchange_rate_snapshot' => 'required|numeric|min:0.001',
            'discount_sar' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.item_type' => 'required|in:service,violation',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price_sar' => 'required|numeric|min:0',
            'items.*.sell_price_jod' => 'required|numeric|min:0',
            'items.*.service_id' => 'nullable|integer',
            'items.*.violation_id' => 'nullable|integer',
        ]);

        DB::transaction(function () use ($invoice, $validated) {
            $rate = $validated['exchange_rate_snapshot'];
            $discount = $validated['discount_sar'] ?? 0;

            $servicesCost = 0;
            $violationsCost = 0;
            $subtotal = 0;
            $totalSellJod = 0;

            foreach ($validated['items'] as $item) {
                $lineTotal = $item['quantity'] * $item['unit_price_sar'];
                $lineSellJod = $item['quantity'] * $item['sell_price_jod'];
                $subtotal += $lineTotal;
                $totalSellJod += $lineSellJod;
                if ($item['item_type'] === 'service') {
                    $servicesCost += $lineTotal;
                } else {
                    $violationsCost += $lineTotal;
                }
            }

            $totalSar = $subtotal - $discount;
            $totalJod = round($totalSellJod, 3);
            $agentCostJod = round($totalSar * $rate, 3);
            $profitJod = round($totalJod - $agentCostJod, 3);
            $profitSar = $rate > 0 ? round($profitJod / $rate, 2) : 0;

            // تحديث بيانات الفاتورة
            $invoice->update([
                'agent_id' => $validated['agent_id'],
                'client_id' => $validated['client_id'],
                'exchange_rate_snapshot' => $rate,
                'subtotal_sar' => $subtotal,
                'discount_sar' => $discount,
                'total_sar' => $totalSar,
                'total_jod' => $totalJod,
                'services_cost_sar' => $servicesCost,
                'violations_cost_sar' => $violationsCost,
                'profit_sar' => $profitSar,
                'profit_jod' => $profitJod,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending',
                'approved_by' => null,
                'approved_at' => null,
            ]);

            // حذف البنود القديمة وإعادة إنشائها
            $invoice->items()->delete();
            foreach ($validated['items'] as $i => $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'item_type' => $item['item_type'],
                    'service_id' => $item['service_id'] ?? null,
                    'violation_id' => $item['violation_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price_sar' => $item['unit_price_sar'],
                    'sell_price_jod' => $item['sell_price_jod'],
                    'total_cost_sar' => $item['quantity'] * $item['unit_price_sar'],
                    'total_sell_jod' => $item['quantity'] * $item['sell_price_jod'],
                    'sort_order' => $i + 1,
                ]);
            }

            AuditLog::log('update', 'invoice', $invoice->id, $invoice->invoice_number);
        });

        return redirect()->back()->with('success', "تم تعديل الفاتورة {$invoice->invoice_number} وإرسالها للاعتماد");
    }

    public function destroy(Invoice $invoice)
    {
        if ($invoice->isApproved()) {
            return back()->with('error', 'لا يمكن حذف فاتورة معتمدة');
        }
        $invoice->items()->delete();
        $invoice->delete();
        return back()->with('success', 'تم حذف الفاتورة');
    }

    // API: مخالفات العميل غير المفوترة
    public function unbilledViolations(Client $client)
    {
        return Violation::where('client_id', $client->id)
            ->where('status', 'approved')
            ->where('billing_status', 'unbilled')
            ->select('id', 'violation_number', 'passport_name', 'cost_sar', 'violation_date')
            ->get();
    }
}
