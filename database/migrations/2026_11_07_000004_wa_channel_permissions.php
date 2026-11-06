<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * صلاحيتا أرقام واتساب (2026-11-06): whatsapp.channels (إدارة الأرقام وربطها) وwhatsapp.outbox (طابور رسائل النظام).
 * تُضافان ولا تُمنحان لأحد هنا: المدير يملك كل شيء، ومنحهما لدور آخر قرار المالك من صفحة الأدوار.
 * لا تشغيل لـ RolePermissionSeeder على الإنتاج: sync() فيه يمسح المنح المخصصة.
 */
return new class extends Migration
{
    private const PERMS = [
        'whatsapp.channels' => 'إدارة أرقام الواتساب',
        'whatsapp.outbox' => 'طابور رسائل النظام في الواتساب',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }
        foreach (self::PERMS as $slug => $name) {
            if (!DB::table('permissions')->where('slug', $slug)->exists()) {
                DB::table('permissions')->insert(['slug' => $slug, 'name' => $name, 'module' => 'whatsapp', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMS))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
