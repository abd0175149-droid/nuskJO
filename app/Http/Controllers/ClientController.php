<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $clients = Client::query()
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%"))
            ->when($request->status !== null, fn ($q) => $q->where('is_active', $request->boolean('status')))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Clients/Index', [
            'title' => 'العملاء',
            'clients' => $clients,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function show(Request $request, Client $client)
    {
        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to = $request->to ?? now()->toDateString();

        $entries = LedgerEntry::where('entity_type', 'client')
            ->where('entity_id', $client->id)
            ->whereBetween('entry_date', [$from, $to . ' 23:59:59'])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $summary = [
            'total_debit' => $entries->sum('debit'),
            'total_credit' => $entries->sum('credit'),
            'opening_balance' => LedgerEntry::where('entity_type', 'client')
                ->where('entity_id', $client->id)
                ->where('entry_date', '<', $from)
                ->orderByDesc('entry_date')->orderByDesc('id')
                ->value('balance_after') ?? 0,
        ];

        return Inertia::render('Clients/Show', [
            'title' => 'كشف حساب: ' . $client->name,
            'client' => $client,
            'entries' => $entries,
            'summary' => $summary,
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function printStatement(Request $request, Client $client)
    {
        $from = $request->from ?: null;
        $to = $request->to ?: null;

        // 1. الفواتير المعتمدة والمعكوسة (editing) للعميل
        $invoicesQuery = \App\Models\Invoice::where('client_id', $client->id)
            ->whereIn('status', ['approved', 'editing']);
        if ($from && $to) {
            $invoicesQuery->whereBetween('invoice_date', [$from, $to . ' 23:59:59']);
        }
        $invoices = $invoicesQuery->with(['items.violation.violationType'])
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get()
            ->map(function ($inv) {
                // تجميع تفاصيل البنود مع معلومات المخالفات الأصلية
                $details = $inv->items->map(function ($item) {
                    if ($item->item_type === 'violation' && $item->violation) {
                        $v = $item->violation;
                        $typeName = $v->violationType?->name ?? 'مخالفة';
                        $passport = $v->passport_name ? " ({$v->passport_name})" : '';
                        return "{$typeName}{$passport} - {$v->cost_sar} SAR";
                    }
                    return $item->description . ' (×' . $item->quantity . ')';
                })->join(' | ');

                // الفواتير بحالة editing تعتبر معكوسة (سالبة)
                $isReversed = $inv->status === 'editing';
                $amount = $isReversed ? -1 * abs($inv->total_jod) : abs($inv->total_jod);

                return [
                    'id' => $inv->id,
                    'date' => $inv->invoice_date->format('Y-m-d'),
                    'invoice_number' => $inv->invoice_number,
                    'details' => $details ?: 'بدون تفاصيل',
                    'amount' => round($amount, 3),
                    'is_reversed' => $isReversed,
                ];
            });

        // 2. سندات القبض المعتمدة للعميل
        $receiptsQuery = \App\Models\Receipt::where('client_id', $client->id)
            ->where('status', 'approved');
        if ($from && $to) {
            $receiptsQuery->whereBetween('receipt_date', [$from, $to . ' 23:59:59']);
        }
        $receipts = $receiptsQuery->orderBy('receipt_date')
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'date' => $r->receipt_date->format('Y-m-d'),
                'receipt_number' => $r->receipt_number,
                'details' => $r->notes ?: '—',
                'payment_method' => match ($r->payment_method) {
                    'cash' => 'نقداً',
                    'bank' => 'بنك',
                    'check' => 'شيك',
                    default => $r->payment_method,
                },
                'amount' => round($r->amount_jod, 3),
            ]);

        // 3. الملخص
        $totalInvoices = $invoices->sum('amount');
        $totalReceipts = $receipts->sum('amount');
        $balance = $totalInvoices - $totalReceipts;

        $summary = [
            'invoices_count' => $invoices->count(),
            'invoices_total' => round($totalInvoices, 3),
            'receipts_count' => $receipts->count(),
            'receipts_total' => round($totalReceipts, 3),
            'balance' => round($balance, 3),
        ];

        // Template & Layout
        $template = \App\Models\Setting::where('key', 'print_template_accounting')->first();
        $templateUrl = $template?->value ? \Illuminate\Support\Facades\Storage::url($template->value) : null;
        $layoutSetting = \App\Models\Setting::where('key', 'print_layout_statement')->first();
        $layout = $layoutSetting?->value ? json_decode($layoutSetting->value, true) : null;

        return Inertia::render('Clients/PrintStatement', [
            'client' => $client,
            'invoices' => $invoices->values(),
            'receipts' => $receipts->values(),
            'summary' => $summary,
            'filters' => ['from' => $from ?? 'الكل', 'to' => $to ?? 'الكل'],
            'templateUrl' => $templateUrl,
            'layout' => $layout,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'country' => 'required|in:JO,SA',
            'city' => 'nullable|string|max:100',
            'contact_person' => 'nullable|string|max:255',
            'id_number' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'credit_limit_jod' => 'nullable|numeric|min:0',
        ]);

        $lastCode = Client::where('code', 'like', 'CLT-%')->orderByDesc('code')->value('code');
        $nextNum = $lastCode ? (int)substr($lastCode, 4) + 1 : 1;
        $validated['code'] = 'CLT-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

        $validated['currency'] = $validated['country'] === 'JO' ? 'JOD' : 'SAR';
        $validated['balance_jod'] = 0;
        $validated['is_active'] = true;
        $validated['credit_limit_jod'] = $validated['credit_limit_jod'] ?? 0;

        $client = Client::create($validated);

        // حساب محاسبي يُنشأ تلقائياً عبر ClientObserver

        return redirect()->route('clients.index')
            ->with('success', 'تم إضافة العميل بنجاح');
    }

    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'country' => 'required|in:JO,SA',
            'city' => 'nullable|string|max:100',
            'contact_person' => 'nullable|string|max:255',
            'id_number' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'credit_limit_jod' => 'nullable|numeric|min:0',
        ]);

        $validated['currency'] = $validated['country'] === 'JO' ? 'JOD' : 'SAR';
        $client->update($validated);

        return redirect()->route('clients.index')
            ->with('success', 'تم تحديث بيانات العميل بنجاح');
    }

    public function destroy(Client $client)
    {
        // منع حذف عميل لديه أي عمليات مسجلة
        $hasLedger = LedgerEntry::where('entity_type', 'client')
            ->where('entity_id', $client->id)->exists();
        
        if ($hasLedger) {
            return back()->with('error', 'لا يمكن حذف عميل تم إجراء عمليات مالية عليه.');
        }

        if ($client->receipts()->count() > 0 || $client->invoices()->count() > 0 || $client->violations()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف عميل لديه عمليات مسجلة.');
        }

        $client->delete();

        return redirect()->route('clients.index')
            ->with('success', 'تم حذف العميل بنجاح');
    }
}
