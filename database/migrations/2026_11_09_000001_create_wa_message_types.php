<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * رسائل النظام (2026-11-06، docs/whatsapp/system-messages.md):
 * - wa_message_types: ما يعدّله المدير على كل نوع (System\Catalog مصدر الأنواع). صف لكل نوع بالمرحلة test: لا يصل
 *   العميل شيء قبل «إطلاق» النوع بعد اختباره (قرار المالك الثالث).
 * - wa_notification_prefs: كتم العميل لكل الرسائل أو لمجموعة أو نوع.
 * - wa_bot_settings: وضع الاختبار العام وأرقام الاختبار.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wa_message_types')) {
            Schema::create('wa_message_types', function (Blueprint $t) {
                $t->id();
                $t->string('key', 40)->unique();
                $t->boolean('enabled')->default(true);
                // auto | approve | off
                $t->string('mode', 10)->default('auto');
                // test | live
                $t->string('stage', 10)->default('test');
                $t->unsignedInteger('tests_count')->default(0);
                $t->timestamp('last_test_at')->nullable();
                $t->unsignedBigInteger('channel_id')->nullable();
                $t->text('template')->nullable();
                $t->json('timing')->nullable();
                $t->unsignedInteger('expires_after_minutes')->nullable();
                $t->unsignedTinyInteger('priority')->nullable();
                $t->boolean('cancel_on_reply')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
            foreach (array_keys(\App\Services\WhatsApp\System\Catalog::TYPES) as $key) {
                DB::table('wa_message_types')->insert(['key' => $key, 'enabled' => true, 'mode' => 'auto', 'stage' => 'test', 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        if (!Schema::hasTable('wa_notification_prefs')) {
            Schema::create('wa_notification_prefs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('client_id')->index();
                $t->string('group', 20)->nullable();
                $t->string('type_key', 40)->nullable();
                $t->timestamp('muted_until')->nullable();
                // bot | staff
                $t->string('source', 10)->default('staff');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        Schema::table('wa_bot_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_bot_settings', 'system_test_mode')) {
                $t->boolean('system_test_mode')->default(false);
            }
            if (!Schema::hasColumn('wa_bot_settings', 'test_recipients')) {
                $t->json('test_recipients')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_notification_prefs');
        Schema::dropIfExists('wa_message_types');
        foreach (['system_test_mode', 'test_recipients'] as $c) {
            if (Schema::hasColumn('wa_bot_settings', $c)) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
