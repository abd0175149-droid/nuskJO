<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Disbursement;
use App\Models\Receipt;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function agentsBalances(Request $request)
    {
        abort_unless(auth()->user()->can('reports.view'), 403);

        // الرصيد بالدينار من الحساب المحاسبي (نفس مصدر كشف حساب الوكيل) — لا الرصيد المبسّط بالريال
        $agents = Agent::query()
            ->with('account:id,balance')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
            ->get(['id', 'name', 'code', 'country', 'currency', 'phone', 'is_active', 'account_id'])
            ->map(function ($a) {
                $a->balance_jod = round((float) ($a->account?->balance ?? 0), 3);
                unset($a->account);
                return $a;
            })
            ->sortByDesc('balance_jod')
            ->values();

        $total = round($agents->sum('balance_jod'), 3);

        return Inertia::render('Reports/AgentsBalances', [
            'title' => 'أرصدة الوكلاء',
            'agents' => $agents,
            'total' => $total,
            'filters' => $request->only(['search']),
        ]);
    }

    public function clientsBalances(Request $request)
    {
        abort_unless(auth()->user()->can('reports.view'), 403);

        // الرصيد بالدينار من الحساب المحاسبي (نفس مصدر كشف الحساب) — لا الرصيد المبسّط
        $clients = Client::query()
            ->with('account:id,balance')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
            ->get(['id', 'name', 'code', 'country', 'currency', 'credit_limit_jod', 'phone', 'is_active', 'account_id'])
            ->map(function ($c) {
                $c->balance_jod = round((float) ($c->account?->balance ?? 0), 3);
                unset($c->account);
                return $c;
            })
            ->sortByDesc('balance_jod')
            ->values();

        $total = round($clients->sum('balance_jod'), 3);

        return Inertia::render('Reports/ClientsBalances', [
            'title' => 'ذمم العملاء',
            'clients' => $clients,
            'total' => $total,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * تقرير الزبائن المسافرين بتاريخ محدد (حسب تاريخ الرحلة على الفاتورة)
     */
    public function tripDate(Request $request)
    {
        abort_unless(auth()->user()->can('reports.view'), 403);

        // ?: يعامل السلسلة الفارغة (?date=) كاليوم، لا كتاريخ فارغ
        $date = $request->date ?: now()->toDateString();

        $invoices = \App\Models\Invoice::query()
            ->with(['client:id,name,code,phone', 'items:id,invoice_id,agent_id,quantity', 'items.agent:id,name,code'])
            ->where('status', 'approved')
            ->whereDate('trip_date', $date)
            ->when(!auth()->user()->isAdmin(), fn ($q) => $q->where('created_by', auth()->id()))
            ->orderBy('invoice_number')
            ->get()
            ->map(fn ($i) => [
                'invoice_number' => $i->invoice_number,
                'client'  => $i->client?->name,
                'phone'   => $i->client_phone ?: $i->client?->phone,
                'trip_date' => $i->trip_date?->toDateString(),
                'agents'  => $i->items->map(fn ($it) => $it->agent?->name)->filter()->unique()->values(),
                'pax'     => (int) $i->items->sum('quantity'),
                'total'   => round((float) $i->total_sell_jod, 3),
                'status'  => $i->status,
            ]);

        return Inertia::render('Reports/TripDate', [
            'title' => 'الزبائن المسافرون بتاريخ',
            'date' => $date,
            'invoices' => $invoices,
            'totalPax' => (int) $invoices->sum('pax'),
            'filters' => ['date' => $date],
        ]);
    }

    /**
     * تقرير أرباح كل موظف — مجموع أرباح الفواتير المعتمدة لعملاء الموظف.
     * مستقل تماماً عن قائمة الدخل (المبنية على دفتر الأستاذ).
     */
    public function employeeProfit(Request $request)
    {
        abort_unless(auth()->user()->can('reports.employee_profit'), 403);

        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to   = $request->to ?? now()->toDateString();

        // تُنسب الأرباح لبائع الفاتورة (sold_by)، وإن لم يُحدَّد فلموظف العميل المسؤول
        $attributionKey = 'COALESCE(invoices.sold_by, clients.employee_id)';

        $rows = \App\Models\Invoice::query()
            ->join('clients', 'clients.id', '=', 'invoices.client_id')
            ->leftJoin('employees', function ($join) use ($attributionKey) {
                $join->on('employees.id', '=', DB::raw($attributionKey));
            })
            ->leftJoin('users', 'users.id', '=', 'employees.user_id')
            ->where('invoices.status', 'approved')
            ->whereBetween('invoices.invoice_date', [$from, $to . ' 23:59:59'])
            ->groupBy(DB::raw($attributionKey), 'users.name', 'employees.employee_number')
            ->selectRaw($attributionKey . ' as employee_id, users.name as employee_name, employees.employee_number as employee_number,
                COUNT(DISTINCT invoices.id) as invoices, COUNT(DISTINCT clients.id) as clients,
                ROUND(SUM(invoices.profit_jod), 3) as profit_jod, ROUND(SUM(invoices.total_sell_jod), 3) as sell_jod')
            ->orderByDesc('profit_jod')
            ->get()
            ->map(fn ($r) => [
                'employee_id' => $r->employee_id,
                'employee' => $r->employee_id ? ($r->employee_name ?: 'موظف #' . $r->employee_id) : 'غير مُسند لموظف',
                'employee_number' => $r->employee_number,
                'invoices' => (int) $r->invoices,
                'clients' => (int) $r->clients,
                'profit_jod' => (float) $r->profit_jod,
                'sell_jod' => (float) $r->sell_jod,
            ]);

        return Inertia::render('Reports/EmployeeProfit', [
            'title' => 'أرباح الموظفين',
            'rows' => $rows,
            'totalProfit' => round((float) $rows->sum('profit_jod'), 3),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    /**
     * منشئ التقارير التفاعلي (تجميع الخدمات حسب الوكيل/العميل)
     */
    public function builder()
    {
        abort_unless(auth()->user()->can('reports.view'), 403);

        return Inertia::render('Reports/Builder', [
            'title' => 'منشئ التقارير',
            'data' => \App\Services\ReportBuilderService::dataset(),
        ]);
    }

    public function profitLoss(Request $request)
    {
        abort_unless(auth()->user()->can('reports.view'), 403);

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
        abort_unless(auth()->user()->can('reports.view'), 403);

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
