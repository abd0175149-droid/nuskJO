<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * حالات التسليم والأحداث الداخلية (E21): وقت ميتا للحالة الحالية لكل رسالة صادرة (status_at)، ومصدر إيقاف التسويق
 * (text أو button أو meta_pref). عمودان nullable بإضافة محروسة، بلا إعادة بناء ولا تعديل لصف قائم ولا فهرس.
 * أعمدة الخطأ (meta_code وerror_code وerror_category) ملك E18، وsent_at (وقت قبول ميتا للطلب) ملك E19.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wa_messages') && !Schema::hasColumn('wa_messages', 'status_at')) {
            Schema::table('wa_messages', fn (Blueprint $t) => $t->timestamp('status_at')->nullable());
        }
        if (Schema::hasTable('wa_optouts') && !Schema::hasColumn('wa_optouts', 'source')) {
            Schema::table('wa_optouts', fn (Blueprint $t) => $t->string('source', 24)->nullable());
        }
    }

    /** العمودان الجديدان وحدهما */
    public function down(): void
    {
        if (Schema::hasColumn('wa_messages', 'status_at')) {
            Schema::table('wa_messages', fn (Blueprint $t) => $t->dropColumn('status_at'));
        }
        if (Schema::hasColumn('wa_optouts', 'source')) {
            Schema::table('wa_optouts', fn (Blueprint $t) => $t->dropColumn('source'));
        }
    }
};
