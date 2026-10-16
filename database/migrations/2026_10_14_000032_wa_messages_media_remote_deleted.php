<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * حذف الملفات المرسلة من خوادم ميتا بعد تسليمها (E32.12، العلم outbound_media_remote_delete): عمود واحد إضافي
 * wa_messages.media_remote_deleted_at. ADD COLUMN بلا إعادة بناء، فلا يمس triggers البحث (trigger التحديث محصور
 * بعمود body_norm). down() فارغة عمداً: dropColumn على SQLite يعيد بناء الجدول، والعمود الفارغ بلا أثر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wa_messages') && !Schema::hasColumn('wa_messages', 'media_remote_deleted_at')) {
            Schema::table('wa_messages', fn (Blueprint $t) => $t->timestamp('media_remote_deleted_at')->nullable());
        }
    }

    public function down(): void
    {
        // لا شيء: إضافة بحتة (انظر الرأس)
    }
};
