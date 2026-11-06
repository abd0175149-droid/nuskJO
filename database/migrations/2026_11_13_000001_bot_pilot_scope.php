<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نطاق رد البوت (2026-11-06): في مرحلة التجربة يرد البوت على المحادثات المختارة وحدها، أي المعلَّمة bot_pilot
 * أو التي هاتفها في قائمة bot_pilot_phones. القاعدة الجديدة تبدأ بـ «all»، لأن الاختبارات تفترض بوتاً يرد على الجميع.
 * أما الصف القائم على الإنتاج فيبدأ بـ «pilot»، وهذا قرار المالك قبل التفعيل الكامل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_bot_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_bot_settings', 'bot_scope')) {
                $t->string('bot_scope', 10)->default('all');
            }
            if (!Schema::hasColumn('wa_bot_settings', 'bot_pilot_phones')) {
                $t->text('bot_pilot_phones')->nullable();
            }
        });
        Schema::table('wa_conversations', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_conversations', 'bot_pilot')) {
                $t->boolean('bot_pilot')->default(false);
            }
        });
        DB::table('wa_bot_settings')->update(['bot_scope' => 'pilot']);
    }

    public function down(): void
    {
        Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropColumn('bot_pilot'));
        Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn(['bot_scope', 'bot_pilot_phones']));
    }
};
