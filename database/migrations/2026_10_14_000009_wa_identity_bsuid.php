<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E11a: عميل باسم مستخدم واتساب قد يصل بلا رقم (from وwa_id محذوفان)، ومعه معرّف ميتا الخاص بنشاطنا (BSUID).
 * المحادثة تُعرف بالمعرّف، والرقم قابل للفراغ. wa_messages بلا تغيير هنا (هجرتها الموحّدة في E11).
 * كل خطوة محروسة، فالتشغيل الثاني لا يفشل.
 */
return new class extends Migration
{
    public function up(): void
    {
        // (أ) إضافات بلا إعادة بناء
        Schema::table('wa_conversations', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_conversations', 'wa_user_id')) {
                $t->string('wa_user_id', 160)->nullable();
            }
            if (!Schema::hasColumn('wa_conversations', 'wa_username')) {
                $t->string('wa_username', 100)->nullable();
            }
            if (!Schema::hasColumn('wa_conversations', 'wa_profile_name')) {
                $t->string('wa_profile_name', 120)->nullable();
            }
        });
        if (!Schema::hasIndex('wa_conversations', 'wa_conversations_wa_user_id_unique')) {
            Schema::table('wa_conversations', fn (Blueprint $t) => $t->unique('wa_user_id'));
        }

        // (ب) الرقم قابل للفراغ: إعادة بناء الجدول في نداء مستقل (لا مفاتيح أجنبية تشير إليه، والفهارس تُعاد)،
        // والفهرس الفريد على phone يبقى (SQLite يقبل عدة NULL فيه)
        if (!$this->nullable('wa_conversations', 'phone') || !$this->nullable('wa_conversations', 'wa_phone')) {
            Schema::table('wa_conversations', function (Blueprint $t) {
                $t->string('phone', 32)->nullable()->change();
                $t->string('wa_phone', 32)->nullable()->change();
            });
        }

        // (ج) إيقاف التسويق لعميل بلا رقم: بالمعرّف
        if (!Schema::hasColumn('wa_optouts', 'wa_user_id')) {
            Schema::table('wa_optouts', fn (Blueprint $t) => $t->string('wa_user_id', 160)->nullable());
        }
        if (!Schema::hasIndex('wa_optouts', 'wa_optouts_wa_user_id_unique')) {
            Schema::table('wa_optouts', fn (Blueprint $t) => $t->unique('wa_user_id'));
        }
        if (!$this->nullable('wa_optouts', 'phone')) {
            Schema::table('wa_optouts', fn (Blueprint $t) => $t->string('phone', 32)->nullable()->change());
        }
    }

    /** لا يعيد NOT NULL: قد توجد صفوف بلا رقم. التراجع الحقيقي استعادة النسخة */
    public function down(): void
    {
        foreach (['wa_conversations' => ['wa_user_id', 'wa_username', 'wa_profile_name'], 'wa_optouts' => ['wa_user_id']] as $table => $columns) {
            if (Schema::hasIndex($table, "{$table}_wa_user_id_unique")) {
                Schema::table($table, fn (Blueprint $t) => $t->dropUnique("{$table}_wa_user_id_unique"));
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }

    private function nullable(string $table, string $column): bool
    {
        return (bool) collect(Schema::getColumns($table))->firstWhere('name', $column)['nullable'];
    }
};
