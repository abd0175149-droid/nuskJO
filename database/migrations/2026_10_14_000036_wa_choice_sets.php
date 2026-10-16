<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الاختيارات التفاعلية وFlow طلب التسعير (E36):
 * - wa_choice_sets: كل قائمة أو أزرار أو نموذج أرسله البوت بمعرّفات يولّدها الخادم (ch:{nonce}:{key})، فتُتحقق النقرة
 *   من المحادثة وcontext.id والحداثة والصلاحية. الخيارات عناوين وأرقام عروض لا بيانات عملاء، وتُقلَّم بعد 30 يوماً.
 * - wa_bot_settings: معرّف الـ Flow المنشور عند ميتا ووضعه (draft للتجربة، published للعملاء).
 * إضافة فقط ومتكررة الأمان: لا يمس wa_messages ولا فهرس بحثها.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wa_choice_sets')) {
            Schema::create('wa_choice_sets', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id')->index();
                // صف wa_messages الصادر ومعرّف ميتا له، يُربطان بعد قبول ميتا
                $t->unsignedBigInteger('message_id')->nullable();
                $t->string('wamid', 128)->nullable()->index();
                // offers_menu | category | offer_option | room_type | booking_actions | quote_flow
                $t->string('kind', 20);
                // 16 حرفاً للقوائم والأزرار، و32 للنموذج (flow_token)
                $t->string('nonce', 40)->unique();
                // [{key, title, description?, ref:{offer_id?, option_ref?, room?, category?}}]
                $t->json('options');
                $t->timestamp('expires_at');
                $t->timestamp('consumed_at')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['conversation_id', 'kind', 'id']);
            });
        }

        Schema::table('wa_bot_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_bot_settings', 'wa_quote_flow_id')) {
                $t->string('wa_quote_flow_id', 32)->nullable();
            }
            if (!Schema::hasColumn('wa_bot_settings', 'wa_quote_flow_mode')) {
                $t->string('wa_quote_flow_mode', 10)->default('published');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_choice_sets');
        foreach (['wa_quote_flow_id', 'wa_quote_flow_mode'] as $column) {
            if (Schema::hasColumn('wa_bot_settings', $column)) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
