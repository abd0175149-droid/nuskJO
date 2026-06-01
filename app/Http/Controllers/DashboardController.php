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

        // إظهار لوحة الاختصارات المبسطة لجميع الموظفين باستثناء المدير العام
        if (!$user->isAdmin()) {
            return Inertia::render('EmployeeDashboard', [
                'title' => 'الرئيسية',
            ]);
        }

        // Admin → لوحة القيادة الكاملة
        $rate = 0.19; // سعر صرف ثابت SAR→JOD

        // بطاقات الإحصائيات
        $stats = [
            'agents_balance_jod' => \App\Models\Account::where('code', 'like', '2110%')->where('code', '!=', '2110')->sum('balance'),
            'clients_balance_jod' => \App\Models\Account::where('code', 'like', '1200%')->where('code', '!=', '1200')->sum('balance'),
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
            ->orderByDesc('created_at')->limit(5)->get(['id','disbursement_number','agent_id','beneficiary_name','amount','currency','status','created_at']);
        $recentInvoices = Invoice::with('client:id,name,code')
            ->orderByDesc('created_at')->limit(5)->get(['id','invoice_number','client_id','total_sell_jod','status','created_at']);

        // إحصائيات الشهر الحالي
        // إحصائيات الشهر الحالي من شجرة الحسابات (JournalEntryLine)
        $monthStart = now()->startOfMonth()->toDateString();
        
        $monthly = [
            'disbursements_jod' => \App\Models\JournalEntryLine::where('debit', '>', 0)
                ->whereHas('journalEntry', function ($q) use ($monthStart) {
                    $q->where('reference_type', 'disbursement')->where('entry_date', '>=', $monthStart);
                })->sum('debit'),
                
            'receipts_jod' => \App\Models\JournalEntryLine::where('credit', '>', 0)
                ->whereHas('journalEntry', function ($q) use ($monthStart) {
                    $q->where('reference_type', 'receipt')->where('entry_date', '>=', $monthStart);
                })->sum('credit'),
                
            'invoices_jod' => \App\Models\JournalEntryLine::where('debit', '>', 0)
                ->whereHas('journalEntry', function ($q) use ($monthStart) {
                    $q->where('reference_type', 'invoice')->where('entry_date', '>=', $monthStart);
                })->sum('debit'),
        ];

        // رسم بياني - آخر 6 أشهر من شجرة الحسابات
        $chartData = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $start = $date->copy()->startOfMonth()->toDateString();
            $end = $date->copy()->endOfMonth()->toDateString();
            $label = $date->format('Y-m');
            
            $chartData[] = [
                'month' => $label,
                'invoices' => (float) \App\Models\JournalEntryLine::where('debit', '>', 0)
                    ->whereHas('journalEntry', function ($q) use ($start, $end) {
                        $q->where('reference_type', 'invoice')->whereBetween('entry_date', [$start, $end]);
                    })->sum('debit'),
                'disbursements' => (float) \App\Models\JournalEntryLine::where('debit', '>', 0)
                    ->whereHas('journalEntry', function ($q) use ($start, $end) {
                        $q->where('reference_type', 'disbursement')->whereBetween('entry_date', [$start, $end]);
                    })->sum('debit'),
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
