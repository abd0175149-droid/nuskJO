<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إزالة ميتا (2026-11-06، المرحلة 6): أعمدة Cloud API في wa_bot_settings (الرمز وسر التطبيق ورمز التحقق ومعرّفات الرقم
 * والحساب وصحة القناة والمكالمات ونموذج Flow وتسعير رسائل الخدمة)، وجداول ميزات ميتا وحدها: أحداث الحساب ورموز QR
 * الرسمية ومصادر المحادثة والمكالمات وسجل التعايش السابق.
 *
 * يبقى ما يقرؤه الكود بعد الانتقال: echo_pause_minutes وcoexistence_holder_user_id (EchoHandler)، وwa_suspended_*،
 * وأعمدة wa_messages كلها (meta_code وfbtrace_id وpricing_* تاريخ رسائل قديمة، وStatusApplier يمسح بعضها).
 * offers.card_qr_link_id يبقى فارغاً بلا مرجع (لا مفتاح أجنبي عليه).
 *
 * لا رجوع: down() لا يعيد البيانات (الأسرار والأحداث ذهبت)، والرجوع إلى ميتا من نسخة احتياطية قبل النشر.
 */
return new class extends Migration
{
    private const SETTINGS_COLUMNS = [
        // الربط والأسرار
        'wa_token', 'wa_token_rotated_at', 'wa_app_secret', 'wa_app_secret_rotated_at', 'wa_app_id',
        'wa_verify_token', 'wa_verify_token_hash', 'wa_verify_token_rotated_at',
        'wa_phone_number_id', 'wa_display_phone', 'wa_waba_id', 'wa_waba_id_source',
        // صحة القناة عند ميتا (E08/E18/E22)
        'wa_credentials_status', 'wa_channel_health', 'wa_last_error',
        'wa_health', 'wa_health_reason', 'wa_health_source', 'wa_health_until', 'wa_health_checked_at', 'wa_health_changed_at',
        'wa_health_unblock_suggested', 'wa_health_meta',
        // المكوّنات الرسمية ونموذج Flow والمكالمات (E36/E37/E39)
        'conversational_automation', 'wa_quote_flow_id', 'wa_quote_flow_mode',
        'calls_answer_mode', 'calls_auto_reject', 'calls_followup_text', 'calls_followup_text_en', 'calling_snapshot',
        // سقف الإرسال المبادر وسعر رسالة الخدمة (تسعير ميتا): حدود الإرسال الآن في wa_send_limits
        'wa_system_daily_cap', 'wa_service_rate_usd',
    ];

    private const TABLES = ['wa_account_events', 'wa_qr_links', 'wa_conversation_entries', 'wa_calls', 'wa_history_chunks'];

    public function up(): void
    {
        if (Schema::hasTable('wa_bot_settings')) {
            foreach (self::SETTINGS_COLUMNS as $column) {
                if (Schema::hasColumn('wa_bot_settings', $column)) {
                    Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // لا رجوع (انظر أعلاه): الأعمدة والجداول تعود بنسخة احتياطية قبل النشر لا بالترحيل
    }
};
