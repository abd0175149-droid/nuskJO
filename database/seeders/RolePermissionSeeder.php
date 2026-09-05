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

        // تنظيف الصلاحيات القديمة وغير المستخدمة
        $obsoleteModules = ['penalties', 'violations', 'violation_types', 'transfers', 'expenses'];
        Permission::whereIn('module', $obsoleteModules)->delete();
        // مسح صلاحية التعديل بعد الاعتماد القديمة إن وجدت
        Permission::where('slug', 'like', '%.edit_approved')->delete();

        // تعريف الصلاحيات لكل وحدة
        $modules = [
            'agents' => ['view', 'create', 'update', 'delete'],
            'clients' => ['view', 'create', 'update', 'delete'],
            'services' => ['view', 'create', 'update', 'delete'],
            'disbursements' => ['view', 'create', 'approve', 'reject'],
            'receipts' => ['view', 'create', 'approve', 'reject'],
            'invoices' => ['view', 'create', 'update', 'submit', 'approve', 'reject'],
            'reports' => ['view', 'employee_profit', 'trip_date'],
            'settings' => ['view', 'update'],
            'users' => ['view', 'create', 'update', 'delete'],

            // HR Module
            'employees' => ['view', 'create', 'update', 'delete'],
            'attendance' => ['view', 'create', 'manual_edit'],
            'leaves' => ['view', 'create', 'approve', 'reject', 'delete'],
            'advances' => ['view', 'create', 'approve', 'reject', 'delete'],
            'payroll' => ['view', 'generate', 'approve', 'reject'],
            'hr_reports' => ['view'],
        ];

        $moduleNames = [
            'agents' => 'الوكلاء', 'clients' => 'العملاء', 'services' => 'الخدمات',
            'disbursements' => 'سندات الصرف', 'receipts' => 'سندات القبض',
            'invoices' => 'الفواتير', 'reports' => 'التقارير', 'settings' => 'الإعدادات',
            'users' => 'المستخدمين', 'employees' => 'الموظفين', 'attendance' => 'الحضور',
            'leaves' => 'الإجازات', 'advances' => 'السلف', 'payroll' => 'الرواتب',
            'hr_reports' => 'تقارير HR',
        ];

        $actionNames = [
            'view' => 'عرض', 'create' => 'إنشاء', 'update' => 'تعديل',
            'delete' => 'حذف', 'approve' => 'اعتماد', 'reject' => 'رفض',
            'submit' => 'إرسال', 'manual_edit' => 'تعديل يدوي', 'generate' => 'توليد',
            'employee_profit' => 'أرباح الموظفين', 'trip_date' => 'تقارير الرحلات',
        ];

        $allPermissions = [];
        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                $slug = "{$module}.{$action}";
                $name = ($actionNames[$action] ?? $action) . ' ' . ($moduleNames[$module] ?? $module);
                $perm = Permission::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $name,
                        'module' => $module,
                    ]
                );
                $allPermissions[$slug] = $perm->id;
            }
        }

        // صلاحيات موظف المبيعات
        $salesPerms = [
            'agents.view', 'clients.view', 'services.view',
            'disbursements.view', 'disbursements.create',
            'receipts.view', 'receipts.create',
            'invoices.view', 'invoices.create', 'invoices.update', 'invoices.submit',
            'reports.view', 'reports.trip_date',
        ];

        // صلاحيات المحاسب
        $accountantPerms = [
            'agents.view', 'clients.view', 'services.view',
            'disbursements.view', 'disbursements.create',
            'receipts.view', 'receipts.create',
            'reports.view',
        ];

        // صلاحيات مدير الموارد البشرية
        $hrPerms = [
            'employees.view', 'employees.create', 'employees.update', 'employees.delete',
            'attendance.view', 'attendance.create', 'attendance.manual_edit',
            'leaves.view', 'leaves.create', 'leaves.approve', 'leaves.reject', 'leaves.delete',
            'advances.view', 'advances.create', 'advances.approve', 'advances.reject', 'advances.delete',
            'payroll.view', 'payroll.generate', 'payroll.approve', 'payroll.reject',
            'hr_reports.view',
        ];

        $salesIds = [];
        foreach ($salesPerms as $slug) {
            if (isset($allPermissions[$slug])) $salesIds[] = $allPermissions[$slug];
        }
        $sales->permissions()->sync($salesIds);

        $accountantIds = [];
        foreach ($accountantPerms as $slug) {
            if (isset($allPermissions[$slug])) $accountantIds[] = $allPermissions[$slug];
        }
        $accountant->permissions()->sync($accountantIds);

        $hrIds = [];
        foreach ($hrPerms as $slug) {
            if (isset($allPermissions[$slug])) $hrIds[] = $allPermissions[$slug];
        }
        $hrManager->permissions()->sync($hrIds);

        // ربط كافة الصلاحيات بـ دور المدير العام لضمان المزامنة الكاملة
        $admin->permissions()->sync(array_values($allPermissions));

        // ضمان ربط حساب المدير العام بدور الأدمن في قاعدة البيانات
        $adminUser = \App\Models\User::where('email', 'admin@nusuk-jo.com')->orWhere('id', 1)->first();
        if ($adminUser) {
            $adminUser->role_id = $admin->id;
            $adminUser->save();
        }
    }
}
