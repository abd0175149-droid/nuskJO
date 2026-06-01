<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DisbursementController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

// Auth Routes (Guest only)
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

// Authenticated Routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Profile
    Route::get('/profile', [\App\Http\Controllers\ProfileController::class, 'index'])->name('profile');
    Route::put('/profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.password');

    // Agents & Clients CRUD
    Route::resource('agents', AgentController::class);
    Route::resource('clients', ClientController::class);

    // سندات الصرف (بديل الحوالات والمصاريف)
    Route::resource('disbursements', DisbursementController::class)->only(['index', 'store', 'destroy']);
    Route::post('disbursements/{disbursement}/approve', [DisbursementController::class, 'approve'])->name('disbursements.approve');
    Route::post('disbursements/{disbursement}/reject', [DisbursementController::class, 'reject'])->name('disbursements.reject');
    Route::post('disbursements/{disbursement}/start-edit', [DisbursementController::class, 'startEdit'])->name('disbursements.start-edit');
    Route::put('disbursements/{disbursement}/update-approved', [DisbursementController::class, 'updateApproved'])->name('disbursements.update-approved');
    Route::get('disbursements/{disbursement}/print', [DisbursementController::class, 'print'])->name('disbursements.print');

    // سندات القبض
    Route::resource('receipts', ReceiptController::class)->only(['index', 'store', 'destroy']);
    Route::post('receipts/{receipt}/approve', [ReceiptController::class, 'approve'])->name('receipts.approve');
    Route::post('receipts/{receipt}/reject', [ReceiptController::class, 'reject'])->name('receipts.reject');
    Route::post('receipts/{receipt}/start-edit', [ReceiptController::class, 'startEdit'])->name('receipts.start-edit');
    Route::put('receipts/{receipt}/update-approved', [ReceiptController::class, 'updateApproved'])->name('receipts.update-approved');

    // الخدمات
    Route::resource('services', ServiceController::class)->only(['index', 'store', 'update', 'destroy']);

    // Settings
    Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
    Route::post('settings/exchange-rate', [SettingController::class, 'storeExchangeRate'])->name('settings.exchange-rate');
    Route::post('settings/upload-template', [SettingController::class, 'uploadTemplate'])->name('settings.upload-template');
    Route::get('settings/print-layout', [SettingController::class, 'printLayout'])->name('settings.print-layout');
    Route::post('settings/print-layout', [SettingController::class, 'savePrintLayout'])->name('settings.save-print-layout');
    Route::resource('attendance-locations', \App\Http\Controllers\AttendanceLocationController::class)->only(['index', 'store', 'update', 'destroy']);

    // Invoices
    Route::resource('invoices', \App\Http\Controllers\InvoiceController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('invoices/{invoice}/approve', [\App\Http\Controllers\InvoiceController::class, 'approve'])->name('invoices.approve');
    Route::post('invoices/{invoice}/reject', [\App\Http\Controllers\InvoiceController::class, 'reject'])->name('invoices.reject');
    Route::post('invoices/{invoice}/start-edit', [\App\Http\Controllers\InvoiceController::class, 'startEdit'])->name('invoices.start-edit');

    // API: تفاصيل فاتورة
    Route::get('api/invoices/{invoice}/details', function(\App\Models\Invoice $invoice) {
        $invoice->load('items.agent:id,name,code');
        return response()->json($invoice);
    });

    // API: FCM Tokens
    Route::post('api/fcm-token', [\App\Http\Controllers\FcmTokenController::class, 'store']);
    Route::delete('api/fcm-token', [\App\Http\Controllers\FcmTokenController::class, 'destroy']);

    // API: Notifications
    Route::get('api/notifications', [\App\Http\Controllers\NotificationController::class, 'index']);
    Route::post('api/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead']);
    Route::post('api/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead']);

    // Expense Categories
    Route::resource('expense-categories', \App\Http\Controllers\ExpenseCategoryController::class)->only(['index', 'store', 'update', 'destroy']);

    // Reports
    Route::get('reports/agents-balances', [\App\Http\Controllers\ReportController::class, 'agentsBalances'])->name('reports.agents-balances');
    Route::get('reports/clients-balances', [\App\Http\Controllers\ReportController::class, 'clientsBalances'])->name('reports.clients-balances');
    Route::get('reports/profit-loss', fn () => redirect('/accounting/profit-loss'));
    Route::get('reports/daily-summary', [\App\Http\Controllers\ReportController::class, 'dailySummary'])->name('reports.daily-summary');

    // Accounting
    Route::get('accounting/chart-of-accounts', [\App\Http\Controllers\AccountingController::class, 'chartOfAccounts'])->name('accounting.chart');
    Route::get('accounting/chart-of-accounts/print', [\App\Http\Controllers\AccountingController::class, 'printChart'])->name('accounting.chart.print');
    Route::post('accounting/accounts', [\App\Http\Controllers\AccountingController::class, 'storeAccount'])->name('accounting.accounts.store');
    Route::get('accounting/accounts/next-code', [\App\Http\Controllers\AccountingController::class, 'nextCode'])->name('accounting.accounts.next-code');
    Route::put('accounting/accounts/{account}', [\App\Http\Controllers\AccountingController::class, 'updateAccount'])->name('accounting.accounts.update');
    Route::delete('accounting/accounts/{account}', [\App\Http\Controllers\AccountingController::class, 'destroyAccount'])->name('accounting.accounts.destroy');
    Route::get('accounting/trial-balance', [\App\Http\Controllers\AccountingController::class, 'trialBalance'])->name('accounting.trial-balance');
    Route::get('accounting/journal-entries', [\App\Http\Controllers\AccountingController::class, 'journalEntries'])->name('accounting.journal-entries');
    Route::post('accounting/journal-entries', [\App\Http\Controllers\AccountingController::class, 'storeJournal'])->name('accounting.journal-entries.store');
    Route::post('accounting/journal-entries/{entry}/reverse', [\App\Http\Controllers\AccountingController::class, 'reverseEntry'])->name('accounting.journal-entries.reverse');
    Route::get('accounting/periods', [\App\Http\Controllers\AccountingController::class, 'periods'])->name('accounting.periods');
    Route::post('accounting/periods/close', [\App\Http\Controllers\AccountingController::class, 'closePeriod'])->name('accounting.periods.close');
    Route::post('accounting/periods/open', [\App\Http\Controllers\AccountingController::class, 'openPeriod'])->name('accounting.periods.open');
    Route::post('accounting/close-year', [\App\Http\Controllers\AccountingController::class, 'closeYear'])->name('accounting.close-year');
    Route::post('accounting/fiscal-years', [\App\Http\Controllers\AccountingController::class, 'storeFiscalYear'])->name('accounting.fiscal-years.store');
    Route::delete('accounting/fiscal-years/{fiscalYear}', [\App\Http\Controllers\AccountingController::class, 'destroyFiscalYear'])->name('accounting.fiscal-years.destroy');
    Route::get('accounting/profit-loss', [\App\Http\Controllers\AccountingController::class, 'profitAndLoss'])->name('accounting.profit-loss');
    Route::get('accounting/balance-sheet', [\App\Http\Controllers\AccountingController::class, 'balanceSheet'])->name('accounting.balance-sheet');
    Route::get('accounting/accounts/{account}/details', [\App\Http\Controllers\AccountingController::class, 'accountDetails'])->name('accounting.accounts.details');

    // Print Pages
    Route::get('invoices/{invoice}/print', [\App\Http\Controllers\InvoiceController::class, 'print'])->name('invoices.print');
    Route::get('agents/{agent}/print-statement', [AgentController::class, 'printStatement'])->name('agents.print-statement');
    Route::get('clients/{client}/print-statement', [ClientController::class, 'printStatement'])->name('clients.print-statement');
    Route::get('receipts/{receipt}/print', [ReceiptController::class, 'print'])->name('receipts.print');
    Route::get('disbursements/{disbursement}/print', [DisbursementController::class, 'print'])->name('disbursements.print.page');
    Route::get('accounting/accounts/{account}/print', [\App\Http\Controllers\AccountingController::class, 'printAccountDetails'])->name('accounting.accounts.print');
    Route::get('accounting/trial-balance/print', [\App\Http\Controllers\AccountingController::class, 'printTrialBalance'])->name('accounting.trial-balance.print');
    Route::get('accounting/profit-loss/print', [\App\Http\Controllers\AccountingController::class, 'printProfitLoss'])->name('accounting.profit-loss.print');
    Route::get('accounting/balance-sheet/print', [\App\Http\Controllers\AccountingController::class, 'printBalanceSheet'])->name('accounting.balance-sheet.print');

    // Users & Roles
    Route::resource('users', \App\Http\Controllers\UserController::class)->except(['create', 'show', 'edit']);
    Route::resource('roles', \App\Http\Controllers\RoleController::class)->except(['create', 'show']);

    // HR Module
    Route::resource('departments', \App\Http\Controllers\DepartmentController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('shifts', \App\Http\Controllers\ShiftController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('employees', \App\Http\Controllers\EmployeeController::class);
    Route::get('employees/{employee}/print-statement', [\App\Http\Controllers\EmployeeController::class, 'printStatement'])->name('employees.print-statement');

    // Attendance
    Route::get('attendance', [\App\Http\Controllers\AttendanceController::class, 'index'])->name('attendance.index');
    Route::post('attendance/check-in', [\App\Http\Controllers\AttendanceController::class, 'checkIn'])->name('attendance.check-in');
    Route::post('attendance/check-out', [\App\Http\Controllers\AttendanceController::class, 'checkOut'])->name('attendance.check-out');
    Route::get('api/attendance/status', [\App\Http\Controllers\AttendanceController::class, 'status'])->name('attendance.status');
    Route::post('attendance/{attendance}/manual-edit', [\App\Http\Controllers\AttendanceController::class, 'manualEdit'])->name('attendance.manual-edit');

    // Leaves
    Route::resource('leaves', \App\Http\Controllers\LeaveController::class)->only(['index', 'store', 'destroy']);
    Route::post('leaves/{leave}/approve', [\App\Http\Controllers\LeaveController::class, 'approve'])->name('leaves.approve');
    Route::post('leaves/{leave}/reject', [\App\Http\Controllers\LeaveController::class, 'reject'])->name('leaves.reject');
    // Leave Types (Settings)
    Route::resource('leave-types', \App\Http\Controllers\LeaveTypeController::class)->only(['index', 'store', 'update', 'destroy']);

    // Advances
    Route::resource('advances', \App\Http\Controllers\AdvanceController::class)->only(['index', 'store', 'destroy']);
    Route::post('advances/{advance}/approve', [\App\Http\Controllers\AdvanceController::class, 'approve'])->name('advances.approve');
    Route::post('advances/{advance}/reject', [\App\Http\Controllers\AdvanceController::class, 'reject'])->name('advances.reject');

    // Payroll
    Route::get('payrolls', [\App\Http\Controllers\PayrollController::class, 'index'])->name('payrolls.index');
    Route::post('payrolls/generate', [\App\Http\Controllers\PayrollController::class, 'generate'])->name('payrolls.generate');
    Route::get('payrolls/{payroll}', [\App\Http\Controllers\PayrollController::class, 'show'])->name('payrolls.show');
    Route::post('payrolls/{payroll}/submit', [\App\Http\Controllers\PayrollController::class, 'submit'])->name('payrolls.submit');
    Route::post('payrolls/{payroll}/approve', [\App\Http\Controllers\PayrollController::class, 'approve'])->name('payrolls.approve');
    Route::post('payrolls/{payroll}/reject', [\App\Http\Controllers\PayrollController::class, 'reject'])->name('payrolls.reject');
    Route::get('payrolls/{payroll}/print', [\App\Http\Controllers\PayrollController::class, 'print'])->name('payrolls.print');
    Route::get('payrolls/{payroll}/export-bank', [\App\Http\Controllers\PayrollController::class, 'exportBank'])->name('payrolls.export-bank');
    Route::get('payslip/{employee}/{month}/{year}', [\App\Http\Controllers\PayrollController::class, 'payslip'])->name('payslip');

    // ESS (Employee Self-Service)
    Route::get('hr/my-attendance', [\App\Http\Controllers\HRReportController::class, 'myAttendance'])->name('hr.my-attendance');
    Route::get('hr/my-requests', [\App\Http\Controllers\HRReportController::class, 'myRequests'])->name('hr.my-requests');
    Route::get('hr/my-payslip', [\App\Http\Controllers\HRReportController::class, 'myPayslip'])->name('hr.my-payslip');

    // HR Reports
    Route::get('hr/reports', [\App\Http\Controllers\HRReportController::class, 'attendance'])->name('hr.reports');
    Route::get('hr/reports/attendance', [\App\Http\Controllers\HRReportController::class, 'attendance'])->name('hr.reports.attendance');
    Route::get('hr/reports/payroll', [\App\Http\Controllers\HRReportController::class, 'payrollSummary'])->name('hr.reports.payroll');
    Route::get('hr/reports/employee-statement/{employee}', [\App\Http\Controllers\HRReportController::class, 'employeeStatement'])->name('hr.reports.employee-statement');
});
