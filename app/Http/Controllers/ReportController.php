<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transfer;
use App\Models\Receipt;
use App\Models\Expense;
use App\Models\Violation;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function agentsBalances(Request $request)
    {
        $agents = Agent::query()
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
            ->orderByDesc('balance_sar')
            ->get(['id', 'name', 'code', 'country', 'currency', 'balance_sar', 'phone', 'is_active']);

        $total = $agents->sum('balance_sar');

        return Inertia::render('Reports/AgentsBalances', [
            'title' => 'أرصدة الوكلاء',
            'agents' => $agents,
            'total' => $total,
            'filters' => $request->only(['search']),
        ]);
    }

    public function clientsBalances(Request $request)
    {
        $clients = Client::query()
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
            ->orderByDesc('balance_jod')
            ->get(['id', 'name', 'code', 'country', 'currency', 'balance_jod', 'credit_limit_jod', 'phone', 'is_active']);

        $total = $clients->sum('balance_jod');

        return Inertia::render('Reports/ClientsBalances', [
            'title' => 'ذمم العملاء',
            'clients' => $clients,
            'total' => $total,
            'filters' => $request->only(['search']),
        ]);
    }

    public function profitLoss(Request $request)
    {
        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to = $request->to ?? now()->toDateString();

        $invoicesJod = Invoice::where('status', 'approved')->whereBetween('invoice_date', [$from, $to])->sum('total_jod');
        $invoicesSar = Invoice::where('status', 'approved')->whereBetween('invoice_date', [$from, $to])->sum('total_sar');
        $transfersSar = Transfer::where('status', 'approved')->whereBetween('transfer_date', [$from, $to])->sum('amount_sar');
        $violationsSar = Violation::where('status', 'approved')->whereBetween('violation_date', [$from, $to])->sum('cost_sar');
        $receiptsJod = Receipt::where('status', 'approved')->whereBetween('receipt_date', [$from, $to])->sum('amount_jod');
        $expensesSar = Expense::where('status', 'approved')->where('currency', 'SAR')->whereBetween('expense_date', [$from, $to])->sum('amount');
        $expensesJod = Expense::where('status', 'approved')->where('currency', 'JOD')->whereBetween('expense_date', [$from, $to])->sum('amount');

        return Inertia::render('Reports/ProfitLoss', [
            'title' => 'الأرباح والخسائر',
            'data' => [
                'invoices_jod' => (float)$invoicesJod,
                'invoices_sar' => (float)$invoicesSar,
                'transfers_sar' => (float)$transfersSar,
                'violations_sar' => (float)$violationsSar,
                'receipts_jod' => (float)$receiptsJod,
                'expenses_sar' => (float)$expensesSar,
                'expenses_jod' => (float)$expensesJod,
            ],
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function dailySummary(Request $request)
    {
        $date = $request->date ?? now()->toDateString();
        $user = auth()->user();
        $isAdmin = $user->role && $user->role->slug === 'admin';

        $disbursementsQuery = \App\Models\Disbursement::with('account:id,name')->whereDate('disbursement_date', $date);
        $receiptsQuery = Receipt::with('client:id,name')->whereDate('receipt_date', $date);
        $invoicesQuery = Invoice::with(['agent:id,name', 'client:id,name'])->whereDate('invoice_date', $date);

        if (!$isAdmin) {
            $disbursementsQuery->where('created_by', $user->id);
            $receiptsQuery->where('created_by', $user->id);
            $invoicesQuery->where('created_by', $user->id);
        }

        $disbursements = $disbursementsQuery->get();
        $receipts = $receiptsQuery->get();
        $invoices = $invoicesQuery->get();

        return Inertia::render('Reports/DailySummary', [
            'title' => 'ملخص يومي',
            'date' => $date,
            'disbursements' => $disbursements,
            'receipts' => $receipts,
            'invoices' => $invoices,
            'totals' => [
                'disbursements' => $disbursements->where('status', 'approved')->sum('amount'),
                'receipts_jod' => $receipts->where('status', 'approved')->sum('amount_jod'),
                'invoices_jod' => $invoices->where('status', 'approved')->sum('total_jod'),
            ],
        ]);
    }
}
