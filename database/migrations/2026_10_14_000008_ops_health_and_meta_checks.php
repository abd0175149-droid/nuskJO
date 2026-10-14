<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E08: معرّف تطبيق ميتا (عام، غير سري) وملخصا آخر فحص للرمز ولصحة القناة.
 * لا رموز ولا ردود خام: الحالة والتواريخ والصلاحيات الناقصة وأخطاء الكيانات بلا معرّفاتها.
 * كل عمود محمي بـ hasColumn، فالتشغيل الثاني لا يفشل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_bot_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_bot_settings', 'wa_app_id')) {
                $t->string('wa_app_id', 32)->nullable();
            }
            if (!Schema::hasColumn('wa_bot_settings', 'wa_credentials_status')) {
                $t->json('wa_credentials_status')->nullable();
            }
            if (!Schema::hasColumn('wa_bot_settings', 'wa_channel_health')) {
                $t->json('wa_channel_health')->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['wa_app_id', 'wa_credentials_status', 'wa_channel_health'] as $column) {
            if (Schema::hasColumn('wa_bot_settings', $column)) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
