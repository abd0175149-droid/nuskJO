<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * صلاحيات صفحة النسخ الاحتياطي. تُضاف بهجرة لا بـ RolePermissionSeeder، لأن الأخير يعيد مزامنة
 * صلاحيات الأدوار فيمسح ما خصّصه المدير. لا تُمنح لأي دور: المدير يمرّ في User::can دائماً.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'backups.view' => 'عرض النسخ الاحتياطي',
        'backups.run' => 'تشغيل نسخة الآن النسخ الاحتياطي',
        'backups.restore_ack' => 'اعتماد مراجعة ما بعد الاستعادة النسخ الاحتياطي',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as $slug => $name) {
            if (!DB::table('permissions')->where('slug', $slug)->exists()) {
                DB::table('permissions')->insert([
                    'slug' => $slug,
                    'name' => $name,
                    'module' => 'backups',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // لا حذف: قد يكون المدير منحها لدور
    }
};
