<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetData extends Command
{
    protected $signature = 'app:reset-data {--confirm}';
    protected $description = 'حذف جميع البيانات مع الحفاظ على الحساب الرئيسي والأدوار والصلاحيات وشجرة الحسابات';

    public function handle()
    {
        if (!$this->option('confirm')) {
            $this->error('أضف --confirm لتأكيد الحذف');
            return 1;
        }

        $this->info('🔄 جاري تفريغ البيانات...');

        DB::statement('PRAGMA foreign_keys = OFF');

        // 1. حذف كل البيانات المالية والعمليات
        $operationTables = [
            'journal_entry_lines', 'journal_entries', 'ledger_entries',
            'invoice_items', 'invoices', 'transfers', 'receipts',
            'expenses', 'violations', 'audit_logs', 'activity_logs',
            'notifications', 'fcm_tokens', 'exchange_rates',
        ];

        foreach ($operationTables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
                $this->line("  ✅ {$table}");
            }
        }

        // 2. حذف بيانات HR
        $hrTables = [
            'payroll_items', 'payrolls', 'advance_installments', 'advances',
            'employee_penalties', 'leave_balances', 'leave_requests',
            'attendances', 'employee_shift_overrides', 'employees',
        ];

        foreach ($hrTables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
                $this->line("  ✅ {$table}");
            }
        }

        // 3. حذف بيانات الأطراف (وكلاء/عملاء)
        foreach (['agents', 'clients'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
                $this->line("  ✅ {$table}");
            }
        }

        // 4. حذف الفترات المحاسبية والسنوات المالية
        foreach (['accounting_periods', 'fiscal_years'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
                $this->line("  ✅ {$table}");
            }
        }

        // 5. حذف الحسابات الفرعية غير النظامية + تصفير أرصدة النظامية
        if (Schema::hasTable('accounts')) {
            $deletedCount = DB::table('accounts')->where('is_system', false)->delete();
            DB::table('accounts')->update(['balance' => 0]);
            $this->line("  ✅ حذف {$deletedCount} حساب فرعي + تصفير أرصدة");
        }

        // 6. حذف المستخدمين عدا المدير الرئيسي (أول مستخدم)
        if (Schema::hasTable('users')) {
            $mainUser = DB::table('users')->orderBy('id')->first();
            if ($mainUser) {
                DB::table('users')->where('id', '!=', $mainUser->id)->delete();
                $this->line("  ✅ users (أبقيت: {$mainUser->email})");
            }
        }

        // 7. إعادة تعيين عدادات الترقيم
        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->whereIn('key', [
                    'next_transfer_number', 'next_receipt_number',
                    'next_expense_number', 'next_invoice_number',
                    'next_violation_number', 'next_advance_number',
                    'next_payroll_number', 'next_journal_number',
                ])
                ->delete();
            $this->line("  ✅ settings (عدادات الترقيم)");
        }

        DB::statement('PRAGMA foreign_keys = ON');

        // 8. إعادة شجرة الحسابات الأساسية والإعدادات
        $this->call('db:seed', ['--class' => 'Database\\Seeders\\ChartOfAccountsSeeder', '--force' => true]);
        $this->call('db:seed', ['--class' => 'Database\\Seeders\\SettingSeeder', '--force' => true]);

        $this->newLine();
        $this->info('✅ تم تفريغ جميع البيانات بنجاح!');
        $this->info('📝 تم الحفاظ على:');
        $this->line('   - الحساب الرئيسي (المدير العام)');
        $this->line('   - الأدوار والصلاحيات');
        $this->line('   - شجرة الحسابات النظامية (أرصدة = 0)');
        $this->line('   - الإعدادات العامة');

        return 0;
    }
}
