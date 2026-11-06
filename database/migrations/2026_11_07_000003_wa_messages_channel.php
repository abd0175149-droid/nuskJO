<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الرسالة للرقم الذي استقبلها أو أرسلها (OpenWA). عمود wamid يبقى باسمه ويحمل معرّف رسالة البوابة
 * (true_/false_{chatId}_{id})، وتفرّده صار مع channel_id: المعرّف من منظور جلسة واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('wa_messages', 'channel_id')) {
            Schema::table('wa_messages', fn (Blueprint $t) => $t->unsignedBigInteger('channel_id')->nullable());
        }
        if (Schema::hasIndex('wa_messages', 'wa_messages_wamid_unique')) {
            Schema::table('wa_messages', fn (Blueprint $t) => $t->dropUnique('wa_messages_wamid_unique'));
        }
        Schema::table('wa_messages', function (Blueprint $t) {
            if (!Schema::hasIndex('wa_messages', 'wa_messages_channel_wamid_unique')) {
                $t->unique(['channel_id', 'wamid'], 'wa_messages_channel_wamid_unique');
            }
            if (!Schema::hasIndex('wa_messages', 'wa_messages_wamid_index')) {
                $t->index('wamid', 'wa_messages_wamid_index');
            }
        });
    }

    public function down(): void
    {
        foreach (['wa_messages_channel_wamid_unique' => 'u', 'wa_messages_wamid_index' => 'i'] as $index => $kind) {
            if (Schema::hasIndex('wa_messages', $index)) {
                Schema::table('wa_messages', fn (Blueprint $t) => $kind === 'u' ? $t->dropUnique($index) : $t->dropIndex($index));
            }
        }
        if (Schema::hasColumn('wa_messages', 'channel_id')) {
            Schema::table('wa_messages', fn (Blueprint $t) => $t->dropColumn('channel_id'));
        }
    }
};
