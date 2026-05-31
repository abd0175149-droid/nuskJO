<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Client;
use App\Models\Disbursement;
use App\Models\Receipt;
use App\Models\Invoice;
use App\Models\Employee;
use App\Models\Attendance;
use App\Models\Payroll;
use App\Models\LeaveRequest;
use App\Models\Advance;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $user = auth()->user();

        // الموظف (غير admin) → واجهة الاختصارات المبسطة
        if (!$user->isAdmin()) {
            return Inertia::render('EmployeeDashboard', [
                'title' => 'الرئيسية',
            ]);
        }

        // Admin → لوحة القيادة الكاملة
        $rate = 0.19; // سعر صرف ثابت SAR→JOD

        // بطاقات الإحصائيات
        $stats = [
            'agents_balance_sar' => Agent::sum('balance_sar'),
            'clients_balance_jod' => Client::sum('balance_jod'),
            'total_agents' => Agent::where('is_active', true)->count(),
            'total_clients' => Client::where('is_active', true)->count(),
        ];

        // عمليات معلقة
        $pending = [
            'disbursements' => Disbursement::where('status', 'pending')->count(),
            'receipts' => Receipt::where('status', 'pending')->count(),
            'invoices' => Invoice::where('status', 'pending')->count(),
        ];
        $pending['total'] = array_sum($pending);

        // آخر العمليات
        $recentTransfers = Disbursement::with('agent:id,name,code')
            ->orderByDesc('created_at')->limit(5)->get(['id','disbursement_number','agent_id','amount','currency','status','created_at']);
        $recentInvoices = Invoice::with('client:id,name,code')
            ->orderByDesc('created_at')->limit(5)->get(['id','invoice_number','client_id','total_sell_jod','status','created_at']);

        // إحصائيات الشهر الحالي
        $monthStart = now()->startOfMonth()->toDateString();
        $monthly = [
            'transfers_sar' => Disbursement::where('status', 'approved')->where('disbursement_date', '>=', $monthStart)->sum('amount'),
            'receipts_jod' => Receipt::where('status', 'approved')->where('receipt_date', '>=', $monthStart)->sum('amount_jod'),
            'invoices_jod' => Invoice::where('status', 'approved')->where('invoice_date', '>=', $monthStart)->sum('total_sell_jod'),
            'expenses_total' => 0,
            'violations_sar' => 0,
        ];

        // رسم بياني - آخر 6 أشهر
        $chartData = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $start = $date->copy()->startOfMonth()->toDateString();
            $end = $date->copy()->endOfMonth()->toDateString();
            $label = $date->format('Y-m');
            $chartData[] = [
                'month' => $label,
                'invoices' => (float) Invoice::where('status', 'approved')->whereBetween('invoice_date', [$start, $end])->sum('total_sell_jod'),
                'expenses' => (float) Disbursement::where('status', 'approved')->whereBetween('disbursement_date', [$start, $end])->sum('amount'),
                'transfers' => 0,
            ];
        }

        // ===== HR KPIs =====
        $hr = [
            'total_employees' => Employee::active()->count(),
            'late_today' => Attendance::where('date', today())->where('status', 'late')->count(),
            'absent_today' => Attendance::where('date', today())->where('status', 'absent')->count(),
            'present_today' => Attendance::where('date', today())->whereIn('status', ['present', 'late'])->count(),
            'pending_leaves' => LeaveRequest::where('status', 'pending')->count(),
            'pending_advances' => Advance::where('status', 'pending')->count(),
            'total_payroll_sar' => (float) Payroll::where('month', now()->month)->where('year', now()->year)->where('currency', 'SAR')->where('status', 'approved')->sum('total_net'),
            'total_payroll_jod' => (float) Payroll::where('month', now()->month)->where('year', now()->year)->where('currency', 'JOD')->where('status', 'approved')->sum('total_net'),
        ];

        return Inertia::render('Dashboard/Index', [
            'title' => 'لوحة القيادة',
            'stats' => $stats,
            'pending' => $pending,
            'recentTransfers' => $recentTransfers,
            'recentInvoices' => $recentInvoices,
            'monthly' => $monthly,
            'chartData' => $chartData,
            'exchangeRate' => $rate,
            'hr' => $hr,
        ]);
    }
}
