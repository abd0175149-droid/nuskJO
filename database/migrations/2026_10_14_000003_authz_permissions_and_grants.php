<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * صلاحيات المحاسبة وإدارة الأدوار (E03). تُضاف بهجرة لا بـ RolePermissionSeeder، لأن الأخير يعيد
 * مزامنة صلاحيات الأدوار فيمسح ما خصّصه المدير. المنح بـ insertOrIgnore: لا يُسحب أي منح قائم.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'accounting.view' => ['عرض المحاسبة', 'accounting'],
        'accounting.manage' => ['إدارة الحسابات والقيود', 'accounting'],
        'accounting.close' => ['إقفال الفترات والسنة', 'accounting'],
        'roles.manage' => ['إدارة الأدوار والصلاحيات', 'roles'],
    ];

    /** جدول المنح المعتمد من المالك (الدور => الصلاحيات). المدير يمرّ في Gate::before دون منح. */
    private const GRANTS = [
        'accountant' => ['accounting.view'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as $slug => [$name, $module]) {
            if (!DB::table('permissions')->where('slug', $slug)->exists()) {
                DB::table('permissions')->insert([
                    'slug' => $slug, 'name' => $name, 'module' => $module,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id', 'slug');
        $grants = self::GRANTS + ['admin' => array_keys(self::PERMISSIONS)];

        foreach ($grants as $roleSlug => $slugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (!$roleId) {
                continue;
            }
            foreach ($slugs as $slug) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids[$slug]]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
