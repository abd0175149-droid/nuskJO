<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // إنشاء الأدوار
        $admin = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'مدير عام', 'description' => 'صلاحيات كاملة']);
        $sales = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'موظف مبيعات', 'description' => 'إدخال عمليات']);
        $accountant = Role::firstOrCreate(['slug' => 'accountant'], ['name' => 'محاسب', 'description' => 'عمليات مالية محدودة']);
        $hrManager = Role::firstOrCreate(['slug' => 'hr_manager'], ['name' => 'مدير موارد بشرية', 'description' => 'إدارة الموظفين والحضور والرواتب']);

        // تعريف الصلاحيات لكل وحدة
        $modules = [
            'agents' => ['view', 'create', 'update', 'delete'],
            'clients' => ['view', 'create', 'update', 'delete'],
            'services' => ['view', 'create', 'update', 'delete'],
            'violation_types' => ['view', 'create', 'update', 'delete'],
            'transfers' => ['view', 'create', 'approve', 'reject'],
            'receipts' => ['view', 'create', 'approve', 'reject'],
            'expenses' => ['view', 'create', 'approve', 'reject'],
            'violations' => ['view', 'create', 'approve', 'reject'],
            'invoices' => ['view', 'create', 'update', 'submit', 'approve', 'reject'],
            'reports' => ['view'],
            'settings' => ['view', 'update'],
            'users' => ['view', 'create', 'update', 'delete'],

            // HR Module
            'employees' => ['view', 'create', 'update', 'delete'],
            'attendance' => ['view', 'create', 'manual_edit'],
            'leaves' => ['view', 'create', 'approve', 'reject', 'delete'],
            'advances' => ['view', 'create', 'approve', 'reject', 'delete'],
            'penalties' => ['view', 'create', 'delete'],
            'payroll' => ['view', 'generate', 'approve', 'reject'],
            'hr_reports' => ['view'],
        ];

        $allPermissions = [];
        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                $slug = "{$module}.{$action}";
                $perm = Permission::firstOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => ucfirst($action) . ' ' . str_replace('_', ' ', $module),
                        'module' => $module,
                    ]
                );
                $allPermissions[$slug] = $perm->id;
            }
        }

        // صلاحيات موظف المبيعات
        $salesPerms = [
            'agents.view', 'clients.view', 'services.view', 'violation_types.view',
            'transfers.view', 'transfers.create',
            'receipts.view', 'receipts.create',
            'violations.view', 'violations.create',
            'invoices.view', 'invoices.create', 'invoices.update', 'invoices.submit',
            'reports.view',
        ];

        // صلاحيات المحاسب
        $accountantPerms = [
            'agents.view', 'clients.view', 'services.view', 'violation_types.view',
            'transfers.view', 'transfers.create',
            'receipts.view', 'receipts.create',
            'expenses.view', 'expenses.create',
            'reports.view',
        ];

        // صلاحيات مدير الموارد البشرية
        $hrPerms = [
            'employees.view', 'employees.create', 'employees.update', 'employees.delete',
            'attendance.view', 'attendance.create', 'attendance.manual_edit',
            'leaves.view', 'leaves.create', 'leaves.approve', 'leaves.reject', 'leaves.delete',
            'advances.view', 'advances.create', 'advances.approve', 'advances.reject', 'advances.delete',
            'penalties.view', 'penalties.create', 'penalties.delete',
            'payroll.view', 'payroll.generate', 'payroll.approve', 'payroll.reject',
            'hr_reports.view',
        ];

        $salesIds = [];
        foreach ($salesPerms as $slug) {
            if (isset($allPermissions[$slug])) {
                $salesIds[] = $allPermissions[$slug];
            }
        }
        $sales->permissions()->syncWithoutDetaching($salesIds);

        $accountantIds = [];
        foreach ($accountantPerms as $slug) {
            if (isset($allPermissions[$slug])) {
                $accountantIds[] = $allPermissions[$slug];
            }
        }
        $accountant->permissions()->syncWithoutDetaching($accountantIds);

        $hrIds = [];
        foreach ($hrPerms as $slug) {
            if (isset($allPermissions[$slug])) {
                $hrIds[] = $allPermissions[$slug];
            }
        }
        $hrManager->permissions()->syncWithoutDetaching($hrIds);

        // ربط كافة الصلاحيات بـ دور المدير العام لضمان المزامنة الكاملة
        $admin->permissions()->syncWithoutDetaching(array_values($allPermissions));

        // ضمان ربط حساب المدير العام بدور الأدمن في قاعدة البيانات
        $adminUser = \App\Models\User::where('email', 'admin@nusuk-jo.com')->orWhere('id', 1)->first();
        if ($adminUser) {
            $adminUser->role_id = $admin->id;
            $adminUser->save();
        }
    }
}
