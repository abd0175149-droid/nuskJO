<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Disbursement;
use App\Models\Receipt;
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
        $disbursementsJod = Disbursement::where('status', 'approved')->whereBetween('disbursement_date', [$from, $to])->sum('amount_jod');
        $receiptsJod = Receipt::where('status', 'approved')->whereBetween('receipt_date', [$from, $to])->sum('amount_jod');

        return Inertia::render('Reports/ProfitLoss', [
            'title' => 'الأرباح والخسائر',
            'data' => [
                'invoices_jod' => (float)$invoicesJod,
                'disbursements_jod' => (float)$disbursementsJod,
                'receipts_jod' => (float)$receiptsJod,
            ],
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function dailySummary(Request $request)
    {
        $date = $request->date ?? now()->toDateString();
        $user = auth()->user();

        $disbQuery = Disbursement::with('agent:id,name')->whereDate('disbursement_date', $date);
        $rectQuery = Receipt::with('client:id,name')->whereDate('receipt_date', $date);
        $invQuery = Invoice::with(['client:id,name'])->whereDate('invoice_date', $date);

        // الموظف يرى الملخص الخاص به فقط
        if (!$user->isAdmin()) {
            $disbQuery->where('created_by', $user->id);
            $rectQuery->where('created_by', $user->id);
            $invQuery->where('created_by', $user->id);
        }

        $disbursements = $disbQuery->get();
        $receipts = $rectQuery->get();
        $invoices = $invQuery->get();

        return Inertia::render('Reports/DailySummary', [
            'title' => 'ملخص يومي',
            'date' => $date,
            'disbursements' => $disbursements,
            'receipts' => $receipts,
            'invoices' => $invoices,
            'totals' => [
                'disbursements_jod' => $disbursements->where('status', 'approved')->sum('amount_jod'),
                'receipts_jod' => $receipts->where('status', 'approved')->sum('amount_jod'),
                'invoices_jod' => $invoices->where('status', 'approved')->sum('total_sell_jod'),
            ],
        ]);
    }
}
