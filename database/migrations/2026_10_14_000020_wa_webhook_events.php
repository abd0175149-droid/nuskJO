<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * استقبال الويبهوك المتين (E20): سجل كل توصيلة موقّعة قبل الإقرار (body_sha256 فريد)، وجسمها مشفّر في
 * النموذج يُمحى فور النجاح ولا يبقى للفاشل أكثر من 72 ساعة. والمرفوض بالسياج بلا جسم أصلاً.
 * ومصدر معرّف حساب الأعمال (learned من entry.id، أو manual من الإعدادات). جدول جديد وعمود، بلا إعادة بناء.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wa_webhook_events')) {
            Schema::create('wa_webhook_events', function (Blueprint $t) {
                $t->id();
                $t->timestamp('received_at')->index();
                $t->char('body_sha256', 64)->unique();
                $t->text('raw_body')->nullable();
                // pending | processed | failed | dead | rejected | malformed
                $t->string('status', 12)->index();
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->timestamp('next_attempt_at')->nullable();
                $t->timestamp('last_attempt_at')->nullable();
                $t->timestamp('processed_at')->nullable();
                $t->timestamp('purge_after')->nullable();
                // أسماء حقول وأعداد فقط: «messages:2,statuses:5»
                $t->string('field_summary', 160)->nullable();
                // {kind, ref, error}: wamid أو رقم العنصر واسم الصنف، بلا نصوص
                $t->json('item_errors')->nullable();
                $t->string('error', 300)->nullable();
                $t->string('reject_reason', 32)->nullable();
            });
        }

        if (Schema::hasTable('wa_bot_settings') && !Schema::hasColumn('wa_bot_settings', 'wa_waba_id_source')) {
            Schema::table('wa_bot_settings', function (Blueprint $t) {
                // learned | manual
                $t->string('wa_waba_id_source', 10)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_webhook_events');
        if (Schema::hasColumn('wa_bot_settings', 'wa_waba_id_source')) {
            Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn('wa_waba_id_source'));
        }
    }
};
