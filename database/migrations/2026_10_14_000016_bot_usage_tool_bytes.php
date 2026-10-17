<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** حجم نتائج الأدوات في كل رد (E15.7): للتحليلات (المتوسط وp90). إضافي ومتكرر الأمان */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wa_bot_usage') && !Schema::hasColumn('wa_bot_usage', 'tool_result_bytes')) {
            Schema::table('wa_bot_usage', fn (Blueprint $t) => $t->unsignedInteger('tool_result_bytes')->default(0));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('wa_bot_usage', 'tool_result_bytes')) {
            Schema::table('wa_bot_usage', fn (Blueprint $t) => $t->dropColumn('tool_result_bytes'));
        }
    }
};
