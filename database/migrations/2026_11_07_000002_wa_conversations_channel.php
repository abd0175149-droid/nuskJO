<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المحادثة لرقم واحد من أرقامنا (OpenWA): عميل يراسل رقمين = محادثتان، فالرقم لم يعد فريداً وحده بل مع channel_id.
 * - jid: معرّف المحادثة على البوابة (…@c.us أو …@lid)، والإرسال إليه.
 * - lid: معرّف الخصوصية حين يصل المرسل خلفه، فتُعرف محادثته به قبل أن يُعرف رقمه.
 * المحادثات القديمة تبقى بـ channel_id فارغ هنا، ولا تصطدم بشيء. أول رسالة من صاحبها تنسبها إلى الرقم الذي وصلت عليه
 * (ContactIdentity::resolveIdentity)، وأول إرسال إليها ينسبها إلى الرقم الوحيد إن لم يكن غيره (WhatsAppChannel::channelFor)،
 * فلا تُفتح له محادثة ثانية. وما بقي منها بلا رقم (رقم ميتا الاختباري) يحذفه whatsapp:purge-legacy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_conversations', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_conversations', 'channel_id')) {
                $t->unsignedBigInteger('channel_id')->nullable();
            }
            if (!Schema::hasColumn('wa_conversations', 'jid')) {
                $t->string('jid', 120)->nullable();
            }
            if (!Schema::hasColumn('wa_conversations', 'lid')) {
                $t->string('lid', 120)->nullable();
            }
        });

        if (Schema::hasIndex('wa_conversations', 'wa_conversations_phone_unique')) {
            Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropUnique('wa_conversations_phone_unique'));
        }
        // معرّف ميتا (BSUID) فريد كان لرقم واحد؛ لا يصل من OpenWA، ويبقى فهرساً عادياً للمحادثات القديمة
        if (Schema::hasIndex('wa_conversations', 'wa_conversations_wa_user_id_unique')) {
            Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropUnique('wa_conversations_wa_user_id_unique'));
            Schema::table('wa_conversations', fn (Blueprint $t) => $t->index('wa_user_id'));
        }
        Schema::table('wa_conversations', function (Blueprint $t) {
            if (!Schema::hasIndex('wa_conversations', 'wa_conversations_channel_phone_unique')) {
                $t->unique(['channel_id', 'phone'], 'wa_conversations_channel_phone_unique');
            }
            if (!Schema::hasIndex('wa_conversations', 'wa_conversations_channel_lid_unique')) {
                $t->unique(['channel_id', 'lid'], 'wa_conversations_channel_lid_unique');
            }
            if (!Schema::hasIndex('wa_conversations', 'wa_conversations_channel_jid_index')) {
                $t->index(['channel_id', 'jid'], 'wa_conversations_channel_jid_index');
            }
            if (!Schema::hasIndex('wa_conversations', 'wa_conversations_phone_index')) {
                $t->index('phone', 'wa_conversations_phone_index');
            }
        });
    }

    public function down(): void
    {
        foreach (['wa_conversations_channel_phone_unique' => 'u', 'wa_conversations_channel_lid_unique' => 'u',
            'wa_conversations_channel_jid_index' => 'i', 'wa_conversations_phone_index' => 'i'] as $index => $kind) {
            if (Schema::hasIndex('wa_conversations', $index)) {
                Schema::table('wa_conversations', fn (Blueprint $t) => $kind === 'u' ? $t->dropUnique($index) : $t->dropIndex($index));
            }
        }
        foreach (['channel_id', 'jid', 'lid'] as $column) {
            if (Schema::hasColumn('wa_conversations', $column)) {
                Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
