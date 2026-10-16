<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * أدوات تشخيص القناة (E23): عمود إضافي واحد وصلاحية واحدة، متكررا الأمان.
 * - wa_admin_numbers.test_recipient: الرقم يستقبل «الإرسال التجريبي» (مطفأ لكل الأرقام؛ المالك يعلّم ما يريد).
 * - ops.view لصفحة «حالة النظام»، تُمنح للمدير وحده هنا. منحها لغيره بقرار المالك وبسكربت موجّه،
 *   لا بـ RolePermissionSeeder (يمسح المنح المخصّصة، A11).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wa_admin_numbers') && !Schema::hasColumn('wa_admin_numbers', 'test_recipient')) {
            Schema::table('wa_admin_numbers', function (Blueprint $table) {
                $table->boolean('test_recipient')->default(false);
            });
        }

        if (!Schema::hasTable('permissions')) {
            return;
        }
        DB::table('permissions')->insertOrIgnore([
            'slug' => 'ops.view', 'name' => 'عرض حالة النظام', 'module' => 'ops',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('permissions')->where('slug', 'ops.view')->value('id');
        if ($id && Schema::hasTable('roles') && ($admin = DB::table('roles')->where('slug', 'admin')->value('id'))) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions') && ($id = DB::table('permissions')->where('slug', 'ops.view')->value('id'))) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        if (Schema::hasColumn('wa_admin_numbers', 'test_recipient')) {
            Schema::table('wa_admin_numbers', function (Blueprint $table) {
                $table->dropColumn('test_recipient');
            });
        }
    }
};
