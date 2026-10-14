<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * سجل التدقيق المركزي (E09): الخطورة والفاعل (موظف، بوت، رقم إدارة، ميتا، النظام) ومن أين جاء الطلب،
 * وentity_id قابل للفراغ، والجدول للإلحاق فقط (مُشغّل يرفض UPDATE؛ الحذف للتقليم الدوري وحده)،
 * وصلاحيتا audit.view وaudit.export للمدير. كل خطوة محروسة، فالتشغيل الثاني لا يغيّر شيئاً.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['audit.view' => 'عرض سجل التدقيق', 'audit.export' => 'تصدير سجل التدقيق'];

    public function up(): void
    {
        // (1) أعمدة إضافية. الصفوف القديمة كلها من موظفين، فالفاعل الافتراضي user صحيح لها
        $columns = [
            'severity' => fn (Blueprint $t) => $t->string('severity', 8)->default('info'),
            'actor_type' => fn (Blueprint $t) => $t->string('actor_type', 16)->default('user'),
            'actor_ref' => fn (Blueprint $t) => $t->string('actor_ref', 64)->nullable(),
            'method' => fn (Blueprint $t) => $t->string('method', 8)->nullable(),
            'path' => fn (Blueprint $t) => $t->string('path', 191)->nullable(),
            'user_agent' => fn (Blueprint $t) => $t->string('user_agent', 255)->nullable(),
            // من E06 عادةً (2026_10_14_000006)
            'request_id' => fn (Blueprint $t) => $t->string('request_id', 64)->nullable(),
        ];
        foreach ($columns as $name => $add) {
            if (!Schema::hasColumn('audit_logs', $name)) {
                Schema::table('audit_logs', $add);
            }
        }

        // (2) entity_id قابل للفراغ: إعادة بناء على SQLite (لا مفاتيح أجنبية تشير إلى الجدول)
        $entityId = collect(Schema::getColumns('audit_logs'))->firstWhere('name', 'entity_id');
        if ($entityId && !$entityId['nullable']) {
            Schema::table('audit_logs', fn (Blueprint $t) => $t->unsignedBigInteger('entity_id')->nullable()->change());
        }

        // (3) فهارس الصفحة وحدها، تخفيفاً للكتابة
        foreach (['action', 'severity', 'actor_type'] as $column) {
            if (!Schema::hasIndex('audit_logs', "audit_logs_{$column}_created_at_index")) {
                Schema::table('audit_logs', fn (Blueprint $t) => $t->index([$column, 'created_at']));
            }
        }
        if (!Schema::hasIndex('audit_logs', 'audit_logs_request_id_index')) {
            Schema::table('audit_logs', fn (Blueprint $t) => $t->index('request_id'));
        }

        // (4) خطورة الصفوف القديمة الحساسة
        DB::table('audit_logs')->whereIn('action', ['delete', 'reject', 'start_edit', 'settle'])->where('severity', 'info')->update(['severity' => 'warn']);

        // (5) بعد إعادة البناء (تُسقط المُشغّلات): للإلحاق فقط
        DB::statement("CREATE TRIGGER IF NOT EXISTS audit_logs_append_only BEFORE UPDATE ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END");

        // (6) الصلاحيتان للمدير وحده، بلا RolePermissionSeeder (يمسح ما خصّصه المدير)
        if (Schema::hasTable('permissions')) {
            $admin = DB::table('roles')->where('slug', 'admin')->value('id');
            foreach (self::PERMISSIONS as $slug => $name) {
                $id = DB::table('permissions')->where('slug', $slug)->value('id')
                    ?? DB::table('permissions')->insertGetId(['slug' => $slug, 'name' => $name, 'module' => 'audit', 'created_at' => now(), 'updated_at' => now()]);
                if ($admin) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $id]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_logs_append_only');
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
        foreach (['action', 'severity', 'actor_type'] as $column) {
            if (Schema::hasIndex('audit_logs', "audit_logs_{$column}_created_at_index")) {
                Schema::table('audit_logs', fn (Blueprint $t) => $t->dropIndex([$column, 'created_at']));
            }
        }
        foreach (['severity', 'actor_type', 'actor_ref', 'method', 'path', 'user_agent'] as $column) {
            if (Schema::hasColumn('audit_logs', $column)) {
                Schema::table('audit_logs', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
