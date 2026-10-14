<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * موثوقية الرد (E07): علامة أعلى وارد خدمه البوت، وسبب التدخّل، ووقت ميتا للوارد، وأقصى عمر للرد، وأعلام التشغيل.
 * إضافات فقط ومتكررة الأمان. التعبئة لا تُنتج ردوداً بأثر رجعي: العلامة = أعلى وارد قبل آخر صادر،
 * فتطابق البوابة القديمة («آخر رسالة ليست من العميل») على البيانات الحالية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_conversations', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_conversations', 'bot_answered_in_id')) {
                $t->unsignedBigInteger('bot_answered_in_id')->nullable();
            }
            if (!Schema::hasColumn('wa_conversations', 'attention_reason')) {
                $t->string('attention_reason', 24)->nullable();
            }
        });
        if (!Schema::hasIndex('wa_conversations', 'wa_conversations_last_inbound_at_index')) {
            Schema::table('wa_conversations', fn (Blueprint $t) => $t->index('last_inbound_at'));
        }

        if (!Schema::hasColumn('wa_messages', 'meta_at')) {
            Schema::table('wa_messages', fn (Blueprint $t) => $t->timestamp('meta_at')->nullable());
        }

        Schema::table('wa_bot_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_bot_settings', 'max_reply_age_minutes')) {
                $t->unsignedInteger('max_reply_age_minutes')->default(10);
            }
            if (!Schema::hasColumn('wa_bot_settings', 'reliability')) {
                $t->json('reliability')->nullable();
            }
        });

        DB::statement(<<<'SQL'
            UPDATE wa_conversations SET bot_answered_in_id = (
                SELECT MAX(i.id) FROM wa_messages i
                WHERE i.conversation_id = wa_conversations.id AND i.direction = 'in'
                  AND i.id < (SELECT MAX(o.id) FROM wa_messages o WHERE o.conversation_id = wa_conversations.id AND o.direction = 'out')
            )
            WHERE bot_answered_in_id IS NULL
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasIndex('wa_conversations', 'wa_conversations_last_inbound_at_index')) {
            Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropIndex(['last_inbound_at']));
        }
        foreach (['wa_conversations' => ['bot_answered_in_id', 'attention_reason'], 'wa_messages' => ['meta_at'], 'wa_bot_settings' => ['max_reply_age_minutes', 'reliability']] as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }
};
