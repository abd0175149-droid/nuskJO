<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * محادثات المجموعات (2026-11-07): تُستورد وتُعرض بأسماء مرسليها، ويرد عليها الموظف من الصندوق وحده. لا بوت ولا
 * رسائل نظام ولا إيقاف تسويق ولا ربط بعميل (GroupChats، والقفل في WhatsAppChannel::gate). jid المحادثة معرّف
 * المجموعة ‎…@g.us، وبلا هاتف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_conversations', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_conversations', 'is_group')) {
                $t->boolean('is_group')->default(false)->index();
            }
        });
        // مجموعة واحدة لكل رقم: حدثان متزامنان لمجموعة جديدة لا ينشئان محادثتين (GroupChats::resolve)
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS wa_conversations_group_jid_unique ON wa_conversations (channel_id, jid) WHERE is_group = 1');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS wa_conversations_group_jid_unique');
        Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropColumn('is_group'));
    }
};
