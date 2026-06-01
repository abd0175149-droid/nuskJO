<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Agent;
use App\Models\Client;
use App\Models\Service;
use App\Models\AuditLog;
use App\Services\NumberingService;
use App\Services\BalanceService;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    private const EXCHANGE_RATE = 0.19;

    public function index(Request $request)
    {
        $invoices = Invoice::query()
            ->with(['client:id,name,code', 'creator:id,name', 'approver:id,name'])
            ->when($request->search, fn ($q, $s) => $q->where('invoice_number', 'like', "%{$s}%")
                ->orWhereHas('client', fn ($q2) => $q2->where('name', 'like', "%{$s}%")))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Invoices/Index', [
            'title' => 'المطالبات',
            'invoices' => $invoices,
            'filters' => $request->only(['search', 'status']),
            'agents' => Agent::where('is_active', true)->select('id', 'name', 'code', 'currency')->get(),
            'clients' => Client::where('is_active', true)->select('id', 'name', 'code', 'phone')->get(),
            'services' => Service::where('is_active', true)->get(),
            'exchangeRate' => self::EXCHANGE_RATE,
        ]);
    }

    public function print(Invoice $invoice)
    {
        $invoice->load(['client:id,name,code', 'items.agent:id,name,code', 'creator:id,name', 'approver:id,name']);

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
            'client_id' => 'required|exists:clients,id',
            'client_phone' => 'nullable|string|max:20',
            'trip_date' => 'nullable|date',
            'sell_price_jod' => 'required|numeric|min:0',
            'discount_jod' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.agent_id' => 'required|exists:agents,id',
            'items.*.description' => 'required|string',
            'items.*.statement' => 'nullable|string|max:1000',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price_jod' => 'required|numeric|min:0',
            'items.*.service_id' => 'nullable|integer',
        ]);

        DB::transaction(function () use ($validated) {
            $rate = self::EXCHANGE_RATE;
            $discountJod = $validated['discount_jod'] ?? 0;
            $sellPriceJod = $validated['sell_price_jod'];

            // حساب الإجماليات
            $totalCostJod = 0;
            foreach ($validated['items'] as $item) {
                $totalCostJod += $item['quantity'] * $item['unit_price_jod'];
            }

            $totalCostSar = round($totalCostJod / $rate, 2); // للتوافق
            $profitJod = round($sellPriceJod - $totalCostJod - $discountJod, 3);

            $invoice = Invoice::create([
                'invoice_number' => NumberingService::generate('INV'),
                'client_id' => $validated['client_id'],
                'client_phone' => $validated['client_phone'] ?? null,
                'trip_date' => $validated['trip_date'] ?? null,
                'exchange_rate_snapshot' => $rate,
                'total_cost_jod' => $totalCostJod,
                'total_cost_sar' => $totalCostSar,
                'total_sell_jod' => $sellPriceJod,
                'discount_jod' => $discountJod,
                'profit_jod' => $profitJod,
                // legacy fields
                'subtotal_sar' => $totalCostSar,
                'discount_sar' => 0,
                'total_sar' => $totalCostSar,
                'total_jod' => $sellPriceJod,
                'services_cost_sar' => $totalCostSar,
                'profit_sar' => $rate > 0 ? round($profitJod / $rate, 2) : 0,
                'invoice_date' => now()->toDateString(),
                'status' => 'pending',
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // حفظ البنود
            foreach ($validated['items'] as $i => $item) {
                $costJod = $item['quantity'] * $item['unit_price_jod'];
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'agent_id' => $item['agent_id'],
                    'item_type' => 'service',
                    'service_id' => $item['service_id'] ?? null,
                    'description' => $item['description'],
                    'statement' => $item['statement'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price_jod' => $item['unit_price_jod'],
                    'total_cost_jod' => $costJod,
                    // legacy SAR fields
                    'unit_price_sar' => round($item['unit_price_jod'] / $rate, 2),
                    'total_cost_sar' => round($costJod / $rate, 2),
                    'sell_price_jod' => 0,
                    'total_sell_jod' => 0,
                    'sort_order' => $i + 1,
                ]);
            }

            // إشعار بانتظار الاعتماد
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

            $invoice->load('items');
            $rate = self::EXCHANGE_RATE;

            // تجميع تكاليف كل وكيل وخصمها (تحويل JOD → SAR)
            $agentCosts = [];
            foreach ($invoice->items as $item) {
                if ($item->agent_id) {
                    $costJod = (float) ($item->total_cost_jod ?: $item->total_cost_sar * $rate);
                    $agentCosts[$item->agent_id] = ($agentCosts[$item->agent_id] ?? 0) + $costJod;
                }
            }

            foreach ($agentCosts as $agentId => $costJod) {
                $agent = Agent::find($agentId);
                if ($agent && $costJod > 0) {
                    $costSar = round($costJod / $rate, 2);
                    BalanceService::debitAgent($agent, $costSar, 'invoice', $invoice->id);
                }
            }

            // إضافة إجمالي البيع على ذمة العميل
            $sellJod = (float) $invoice->total_sell_jod;
            if ($sellJod > 0) {
                BalanceService::debitClient(
                    $invoice->client,
                    $sellJod,
                    'invoice',
                    $invoice->id
                );
            }

            // قيد محاسبي
            try { AccountingService::recordInvoice($invoice); } catch (\Exception $e) { \Log::error('Accounting Invoice: ' . $e->getMessage()); }

            // إشعار
            if ($invoice->created_by && $invoice->created_by !== auth()->id()) {
                try { \App\Services\NotificationService::send($invoice->created_by, '✅ تم اعتماد فاتورتك', "تم اعتماد الفاتورة {$invoice->invoice_number}", ['type' => 'invoice', 'icon' => '✅', 'action_url' => '/invoices']); } catch (\Throwable $e) {}
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

        try { \App\Services\NotificationService::operationRejected($invoice, 'فاتورة', $invoice->invoice_number); } catch (\Throwable $e) {}

        return back()->with('success', 'تم رفض الفاتورة');
    }

    public function startEdit(Invoice $invoice)
    {
        if (!$invoice->isApproved()) {
            return back()->with('error', 'يمكن تعديل الفواتير المعتمدة فقط');
        }

        DB::transaction(function () use ($invoice) {
            $invoice->load('items');
            $rate = self::EXCHANGE_RATE;

            // عكس خصم كل وكيل
            $agentCosts = [];
            foreach ($invoice->items as $item) {
                if ($item->agent_id) {
                    $costJod = (float) ($item->total_cost_jod ?: $item->total_cost_sar * $rate);
                    $agentCosts[$item->agent_id] = ($agentCosts[$item->agent_id] ?? 0) + $costJod;
                }
            }
            foreach ($agentCosts as $agentId => $costJod) {
                $agent = Agent::find($agentId);
                if ($agent && $costJod > 0) {
                    $costSar = round($costJod / $rate, 2);
                    BalanceService::reverseAgentDebit($agent, $costSar, 'invoice', $invoice->id);
                }
            }

            // عكس ذمة العميل
            $sellJod = (float) $invoice->total_sell_jod;
            if ($sellJod > 0) {
                BalanceService::reverseClientDebit($invoice->client, $sellJod, 'invoice', $invoice->id);
            }

            // عكس القيد المحاسبي
            $entry = \App\Models\JournalEntry::where('reference_type', 'invoice')
                ->where('reference_id', $invoice->id)->where('is_reversed', false)->first();
            if ($entry) try { AccountingService::reverseEntry($entry, 'تعديل الفاتورة'); } catch (\Exception $e) {}

            $invoice->startEditing(auth()->user());
            AuditLog::log('start_edit', 'invoice', $invoice->id, $invoice->invoice_number);
        });

        return back()->with('success', "تم فتح الفاتورة {$invoice->invoice_number} للتعديل");
    }

    public function update(Request $request, Invoice $invoice)
    {
        if ($invoice->status !== 'editing' && $invoice->status !== 'pending') {
            return back()->with('error', 'لا يمكن تعديل هذه الفاتورة');
        }

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'client_phone' => 'nullable|string|max:20',
            'trip_date' => 'nullable|date',
            'sell_price_jod' => 'required|numeric|min:0',
            'discount_jod' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.agent_id' => 'required|exists:agents,id',
            'items.*.description' => 'required|string',
            'items.*.statement' => 'nullable|string|max:1000',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price_jod' => 'required|numeric|min:0',
            'items.*.service_id' => 'nullable|integer',
        ]);

        DB::transaction(function () use ($invoice, $validated) {
            $rate = self::EXCHANGE_RATE;
            $discountJod = $validated['discount_jod'] ?? 0;
            $sellPriceJod = $validated['sell_price_jod'];

            $totalCostJod = 0;
            foreach ($validated['items'] as $item) {
                $totalCostJod += $item['quantity'] * $item['unit_price_jod'];
            }

            $totalCostSar = round($totalCostJod / $rate, 2);
            $profitJod = round($sellPriceJod - $totalCostJod - $discountJod, 3);

            $invoice->update([
                'client_id' => $validated['client_id'],
                'client_phone' => $validated['client_phone'] ?? null,
                'trip_date' => $validated['trip_date'] ?? null,
                'exchange_rate_snapshot' => $rate,
                'total_cost_jod' => $totalCostJod,
                'total_cost_sar' => $totalCostSar,
                'total_sell_jod' => $sellPriceJod,
                'discount_jod' => $discountJod,
                'profit_jod' => $profitJod,
                'subtotal_sar' => $totalCostSar,
                'total_sar' => $totalCostSar,
                'total_jod' => $sellPriceJod,
                'services_cost_sar' => $totalCostSar,
                'profit_sar' => $rate > 0 ? round($profitJod / $rate, 2) : 0,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending',
                'approved_by' => null,
                'approved_at' => null,
            ]);

            $invoice->items()->delete();
            foreach ($validated['items'] as $i => $item) {
                $costJod = $item['quantity'] * $item['unit_price_jod'];
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'agent_id' => $item['agent_id'],
                    'item_type' => 'service',
                    'service_id' => $item['service_id'] ?? null,
                    'description' => $item['description'],
                    'statement' => $item['statement'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price_jod' => $item['unit_price_jod'],
                    'total_cost_jod' => $costJod,
                    'unit_price_sar' => round($item['unit_price_jod'] / $rate, 2),
                    'total_cost_sar' => round($costJod / $rate, 2),
                    'sell_price_jod' => 0,
                    'total_sell_jod' => 0,
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
}
