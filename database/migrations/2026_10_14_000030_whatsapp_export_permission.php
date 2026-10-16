<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * تصدير محادثة واحدة (E30): صلاحية whatsapp.export للمدير وحده. منحها لغيره بقرار المالك وبسكربت موجّه،
 * لا بـ RolePermissionSeeder (يمسح المنح المخصّصة، A11). متكررة الأمان.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }
        DB::table('permissions')->insertOrIgnore([
            'slug' => 'whatsapp.export', 'name' => 'تصدير محادثة واتساب', 'module' => 'whatsapp',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('permissions')->where('slug', 'whatsapp.export')->value('id');
        if ($id && ($admin = DB::table('roles')->where('slug', 'admin')->value('id'))) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions') && ($id = DB::table('permissions')->where('slug', 'whatsapp.export')->value('id'))) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
