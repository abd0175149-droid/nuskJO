<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * دفتر الإرسال (E19): عدد المحاولات، وأعلى وارد غطّاه الرد، ووقت قبول ميتا، وفهرس المنظّف (status, created_at).
 * send_key (E11) يُضاف هنا فقط إن غاب. إضافات محروسة بلا إعادة بناء. وحالة 'unconfirmed' (E12) تصير 'unknown'.
 */
return new class extends Migration
{
    private const INDEX = 'wa_messages_status_created_index';

    public function up(): void
    {
        if (!Schema::hasTable('wa_messages')) {
            return;
        }
        Schema::table('wa_messages', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_messages', 'send_key')) {
                $t->string('send_key', 64)->nullable();
            }
            if (!Schema::hasColumn('wa_messages', 'attempts')) {
                $t->unsignedTinyInteger('attempts')->default(0);
            }
            if (!Schema::hasColumn('wa_messages', 'answers_in_id')) {
                $t->unsignedBigInteger('answers_in_id')->nullable();
            }
            if (!Schema::hasColumn('wa_messages', 'sent_at')) {
                $t->timestamp('sent_at')->nullable();
            }
        });
        if (!Schema::hasIndex('wa_messages', 'wa_messages_send_key_unique')) {
            Schema::table('wa_messages', fn (Blueprint $t) => $t->unique('send_key'));
        }
        if (!Schema::hasIndex('wa_messages', self::INDEX)) {
            Schema::table('wa_messages', fn (Blueprint $t) => $t->index(['status', 'created_at'], self::INDEX));
        }

        DB::table('wa_messages')->where('status', 'unconfirmed')->update(['status' => 'unknown']);
    }

    /** send_key ملك E11 فلا يُحذف، و'unknown' تحمل معنى 'unconfirmed' نفسه فلا تُعاد */
    public function down(): void
    {
        if (Schema::hasIndex('wa_messages', self::INDEX)) {
            Schema::table('wa_messages', fn (Blueprint $t) => $t->dropIndex(self::INDEX));
        }
        foreach (['attempts', 'answers_in_id', 'sent_at'] as $column) {
            if (Schema::hasColumn('wa_messages', $column)) {
                Schema::table('wa_messages', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
