<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إشعار القراءة ومؤشر الكتابة (E35): إعداد واحد للوضع (off افتراضياً، فالنشر لا يغيّر ما يراه العميل)،
 * وأعلى وارد أُبلغت ميتا بقراءته لكل محادثة (يمنع إعادة الطلب نفسه). إضافة عمودين بلا إعادة بناء، ومتكررة الأمان.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wa_bot_settings') && !Schema::hasColumn('wa_bot_settings', 'send_read_receipts')) {
            Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->string('send_read_receipts', 12)->default('off'));
        }
        if (Schema::hasTable('wa_conversations') && !Schema::hasColumn('wa_conversations', 'read_receipt_upto_id')) {
            Schema::table('wa_conversations', fn (Blueprint $t) => $t->unsignedBigInteger('read_receipt_upto_id')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('wa_conversations', 'read_receipt_upto_id')) {
            Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropColumn('read_receipt_upto_id'));
        }
        if (Schema::hasColumn('wa_bot_settings', 'send_read_receipts')) {
            Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn('send_read_receipts'));
        }
    }
};
