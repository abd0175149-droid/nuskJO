<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Events\DataUpdated;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AgentController extends Controller
{
    public function index(Request $request)
    {
        $agents = Agent::query()
            ->with('clients:id,name,code,phone,balance_jod,agent_id')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%"))
            ->when($request->status !== null, fn ($q) => $q->where('is_active', $request->boolean('status')))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Agents/Index', [
            'title' => 'الوكلاء',
            'agents' => $agents,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Agents/Form', [
            'title' => 'إضافة وكيل جديد',
            'agent' => null,
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
            'address' => 'nullable|string|max:500',
            'contact_person' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $codes = Agent::withTrashed()->where('code', 'like', 'AGT-%')->pluck('code')->map(fn($c) => (int)substr($c, 4));
        $nextNum = $codes->max() ? $codes->max() + 1 : 1;
        $validated['code'] = 'AGT-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

        $validated['currency'] = $validated['country'] === 'JO' ? 'JOD' : 'SAR';
        $validated['balance_sar'] = 0;
        $validated['is_active'] = true;

        $agent = Agent::create($validated);

        event(new DataUpdated('agent', 'created', $agent->id));

        return redirect()->route('agents.index')
            ->with('success', 'تم إضافة الوكيل بنجاح');
    }

    public function show(Request $request, Agent $agent)
    {
        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to = $request->to ?? now()->toDateString();

        if (!$agent->account_id) {
            $entries = collect([]);
            $summary = ['total_debit' => 0, 'total_credit' => 0, 'opening_balance' => 0, 'current_balance' => 0];
        } else {
            $lines = \App\Models\JournalEntryLine::where('account_id', $agent->account_id)
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->whereBetween('journal_entries.entry_date', [$from, $to . ' 23:59:59'])
                ->orderBy('journal_entries.entry_date')
                ->orderBy('journal_entries.id')
                ->select(
                    'journal_entry_lines.id',
                    'journal_entry_lines.debit',
                    'journal_entry_lines.credit',
                    'journal_entry_lines.description as line_description',
                    'journal_entries.entry_number',
                    'journal_entries.entry_date',
                    'journal_entries.description as entry_description',
                    'journal_entries.reference_type',
                    'journal_entries.reference_id',
                    'journal_entries.is_reversed'
                )
                ->get();

            $openingDebit = \App\Models\JournalEntryLine::where('account_id', $agent->account_id)
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('journal_entries.entry_date', '<', $from)
                ->sum('journal_entry_lines.debit');
            
            $openingCredit = \App\Models\JournalEntryLine::where('account_id', $agent->account_id)
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('journal_entries.entry_date', '<', $from)
                ->sum('journal_entry_lines.credit');

            // Agent is liability (2110), so credit - debit.
            $openingBalance = $openingCredit - $openingDebit;
            
            $runningBalance = $openingBalance;
            $entries = $lines->map(function($line) use (&$runningBalance) {
                $runningBalance += ($line->credit - $line->debit);
                return [
                    'id' => $line->id,
                    'entry_date' => $line->entry_date,
                    'description' => $line->line_description ?: $line->entry_description,
                    'transaction_type' => $line->is_reversed ? 'reversal' : $line->reference_type,
                    'transaction_id' => $line->reference_id,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                    'balance_after' => $runningBalance
                ];
            });

            $account = \App\Models\Account::find($agent->account_id);
            $summary = [
                'total_debit' => round($lines->sum('debit'), 3),
                'total_credit' => round($lines->sum('credit'), 3),
                'opening_balance' => round($openingBalance, 3),
                'current_balance' => round($account ? $account->balance : 0, 3)
            ];
        }

        return Inertia::render('Agents/Show', [
            'title' => 'كشف حساب: ' . $agent->name,
            'agent' => $agent,
            'entries' => $entries,
            'summary' => $summary,
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }


    public function edit(Agent $agent)
    {
        return Inertia::render('Agents/Form', [
            'title' => 'تعديل الوكيل: ' . $agent->name,
            'agent' => $agent,
        ]);
    }

    public function update(Request $request, Agent $agent)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'country' => 'required|in:JO,SA',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:500',
            'contact_person' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $validated['currency'] = $validated['country'] === 'JO' ? 'JOD' : 'SAR';
        $agent->update($validated);

        return redirect()->route('agents.index')
            ->with('success', 'تم تحديث بيانات الوكيل بنجاح');
    }

    public function destroy(Agent $agent)
    {
        // منع حذف وكيل لديه أي عمليات مسجلة
        $hasLedger = LedgerEntry::where('entity_type', 'agent')
            ->where('entity_id', $agent->id)->exists();
        
        if ($hasLedger) {
            return back()->with('error', 'لا يمكن حذف وكيل تم إجراء عمليات مالية عليه.');
        }

        if ($agent->transfers()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف وكيل لديه حوالات مسجلة.');
        }

        if ($agent->clients()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف وكيل لديه عملاء مرتبطون.');
        }

        $agent->delete();

        return redirect()->route('agents.index')
            ->with('success', 'تم حذف الوكيل بنجاح');
    }


    public function printStatement(Request $request, Agent $agent)
    {
        if (!$agent->account_id) {
            return back()->with('error', 'الوكيل غير مربوط بحساب مالي');
        }
        
        return redirect()->route('accounting.accounts.print', [
            'account' => $agent->account_id,
            'from' => $request->from,
            'to' => $request->to
        ]);
    }
}
