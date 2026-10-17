<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * أعمدة المتابعة (E06): رمز خطأ ميتا وfbtrace_id على الرسائل، ورقم الطلب على التدقيق.
 * إضافات فقط، كلها nullable، ومتكررة الأمان.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wa_messages')) {
            Schema::table('wa_messages', function (Blueprint $t) {
                if (!Schema::hasColumn('wa_messages', 'meta_code')) {
                    $t->unsignedInteger('meta_code')->nullable();
                }
                if (!Schema::hasColumn('wa_messages', 'fbtrace_id')) {
                    $t->string('fbtrace_id', 64)->nullable();
                }
            });
        }
        if (Schema::hasTable('audit_logs') && !Schema::hasColumn('audit_logs', 'request_id')) {
            Schema::table('audit_logs', fn (Blueprint $t) => $t->string('request_id', 64)->nullable());
        }
    }

    public function down(): void
    {
        foreach (['meta_code', 'fbtrace_id'] as $column) {
            if (Schema::hasColumn('wa_messages', $column)) {
                Schema::table('wa_messages', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
        if (Schema::hasColumn('audit_logs', 'request_id')) {
            Schema::table('audit_logs', fn (Blueprint $t) => $t->dropColumn('request_id'));
        }
    }
};
