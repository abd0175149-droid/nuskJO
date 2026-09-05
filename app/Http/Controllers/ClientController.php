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
            ->with('account:id,balance')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%"))
            ->when($request->status !== null, fn ($q) => $q->where('is_active', $request->boolean('status')))
            ->when($request->filled('employee_id'), function ($q) use ($request) {
                // 'none' = العملاء غير المُسندين لأي موظف
                $request->employee_id === 'none'
                    ? $q->whereNull('employee_id')
                    : $q->where('employee_id', $request->employee_id);
            })
            ->orderByRaw('abs(balance_jod) DESC')
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Clients/Index', [
            'title' => 'العملاء',
            'clients' => $clients,
            'employees' => \App\Models\Employee::with('user:id,name')->where('is_active', true)->get()
                ->map(fn ($e) => ['id' => $e->id, 'name' => $e->user?->name ?? ('موظف #' . $e->id)])
                ->values(),
            'filters' => $request->only(['search', 'status', 'employee_id']),
        ]);
    }

    public function show(Request $request, Client $client)
    {
        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to = $request->to ?? now()->toDateString();

        if (!$client->account_id) {
            return back()->with('error', 'العميل غير مربوط بحساب مالي');
        }

        $account = \App\Models\Account::findOrFail($client->account_id);

        $entries = \App\Models\JournalEntryLine::where('account_id', $account->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereBetween('journal_entries.entry_date', [$from, $to . ' 23:59:59'])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->select(
                'journal_entry_lines.*',
                'journal_entries.entry_number',
                'journal_entries.entry_date',
                'journal_entries.description as entry_description',
                'journal_entries.reference_type',
                'journal_entries.reference_id',
                'journal_entries.is_reversed',
            )
            ->get();

        $openingDebit = \App\Models\JournalEntryLine::where('account_id', $account->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.entry_date', '<', $from)
            ->sum('journal_entry_lines.debit');
            
        $openingCredit = \App\Models\JournalEntryLine::where('account_id', $account->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.entry_date', '<', $from)
            ->sum('journal_entry_lines.credit');

        // حساب العميل مدين طبيعته Asset
        $openingBalance = $openingDebit - $openingCredit;

        // حساب الرصيد التراكمي لكل حركة
        $currentBalance = $openingBalance;
        foreach ($entries as $entry) {
            $currentBalance += ($entry->debit - $entry->credit);
            $entry->balance_after = round($currentBalance, 3);
        }

        // تحميل ملاحظات العمليات المرتبطة (فواتير، سندات قبض، إلخ)
        $referenceGroups = $entries->groupBy('reference_type');
        $notesMap = [];

        foreach ($referenceGroups as $type => $group) {
            $ids = $group->pluck('reference_id')->filter()->unique()->values()->toArray();
            if (empty($ids)) continue;

            $modelClass = match ($type) {
                'invoice' => \App\Models\Invoice::class,
                'receipt' => \App\Models\Receipt::class,
                'transfer' => \App\Models\Transfer::class,
                'expense' => \App\Models\Expense::class,
                'disbursement' => \App\Models\Disbursement::class,
                default => null,
            };

            if ($modelClass) {
                $records = $modelClass::whereIn('id', $ids)->select('id', 'notes')->get();
                foreach ($records as $record) {
                    $notesMap["{$type}_{$record->id}"] = $record->notes;
                }
            }
        }

        foreach ($entries as $entry) {
            $key = "{$entry->reference_type}_{$entry->reference_id}";
            $entry->reference_notes = $notesMap[$key] ?? null;
        }

        $summary = [
            'total_debit' => $entries->sum('debit'),
            'total_credit' => $entries->sum('credit'),
            'opening_balance' => round($openingBalance, 3),
            'current_balance' => round($currentBalance, 3),
        ];

        return Inertia::render('Clients/Show', [
            'title' => 'كشف حساب: ' . $client->name,
            'client' => $client,
            'entries' => $entries,
            'summary' => $summary,
            'filters' => ['from' => $from, 'to' => $to],
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
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        $codes = Client::withTrashed()->where('code', 'like', 'CLT-%')->pluck('code')->map(fn($c) => (int)substr($c, 4));
        $nextNum = $codes->max() ? $codes->max() + 1 : 1;
        $validated['code'] = 'CLT-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

        $validated['currency'] = $validated['country'] === 'JO' ? 'JOD' : 'SAR';
        $validated['balance_jod'] = 0;
        $validated['is_active'] = true;
        $validated['credit_limit_jod'] = $validated['credit_limit_jod'] ?? 0;
        $validated['created_by'] = auth()->id();

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
            'employee_id' => 'nullable|exists:employees,id',
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

        if ($client->receipts()->count() > 0 || $client->invoices()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف عميل لديه عمليات مسجلة.');
        }

        $client->delete();

        return redirect()->route('clients.index')
            ->with('success', 'تم حذف العميل بنجاح');
    }

    public function printStatement(Request $request, Client $client)
    {
        if (!$client->account_id) {
            return back()->with('error', 'العميل غير مربوط بحساب مالي');
        }
        
        return redirect()->route('accounting.accounts.print', [
            'account' => $client->account_id,
            'from' => $request->from,
            'to' => $request->to
        ]);
    }
}
