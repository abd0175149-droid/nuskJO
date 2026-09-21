<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حقول القياس: ما لا نسجّله لا نستطيع قياسه.
 *  • tools_used  — أي أدوات استُدعيت في هذا الردّ (لقياس الأدوات ونسبة الاستعمال)
 *  • leaked      — هل عمل حارس تسريب الأدوات
 *  • latency_ms  — زمن توليد الردّ
 *  • model_prices — أسعار كل نموذج، فيُسعَّر كل صفّ بنموذجه هو
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_bot_usage', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_bot_usage', 'tools_used')) {
                $t->json('tools_used')->nullable();
            }
            if (!Schema::hasColumn('wa_bot_usage', 'leaked')) {
                $t->boolean('leaked')->default(false);
            }
            if (!Schema::hasColumn('wa_bot_usage', 'latency_ms')) {
                $t->unsignedInteger('latency_ms')->default(0);
            }
        });

        Schema::table('wa_bot_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_bot_settings', 'model_prices')) {
                // {"gemini-2.5-flash":{"in":0.30,"out":2.50}, ...}  بالدولار لكل مليون توكن
                $t->json('model_prices')->nullable();
            }
            if (!Schema::hasColumn('wa_bot_settings', 'cache_discount')) {
                // توكنز الكاش تُفوتَر بربع سعر الإدخال
                $t->decimal('cache_discount', 4, 3)->default(0.250);
            }
        });
    }

    public function down(): void
    {
        Schema::table('wa_bot_usage', function (Blueprint $t) {
            foreach (['tools_used', 'leaked', 'latency_ms'] as $c) {
                if (Schema::hasColumn('wa_bot_usage', $c)) $t->dropColumn($c);
            }
        });
        Schema::table('wa_bot_settings', function (Blueprint $t) {
            foreach (['model_prices', 'cache_discount'] as $c) {
                if (Schema::hasColumn('wa_bot_settings', $c)) $t->dropColumn($c);
            }
        });
    }
};
