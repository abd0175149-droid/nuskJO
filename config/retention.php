<?php

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\WaAccountEvent;
use App\Models\WaAdminAction;
use App\Models\WaBotUsage;

/*
|--------------------------------------------------------------------------
| دورة حياة البيانات (E13)
|--------------------------------------------------------------------------
| المكان الوحيد لقراءة env في هذه الملحمة (الحاوية تشغّل config:cache). القيمة 0 للمحادثات = معطّل حتى
| يقرّر المالك (المقترح 730 مطابقةً لـ /privacy). prunable_models قائمة بيضاء صريحة: لا يُقلَّم جدول غير
| مذكور، ولا جدول مالي أبداً (RetentionWhitelistTest).
*/

return [
    'conversations_days' => (int) env('WA_RETENTION_DAYS', 0),
    'admin_chats_days' => (int) env('WA_ADMIN_CHAT_RETENTION_DAYS', 0),

    'notifications_read_days' => 90,
    'notifications_unread_days' => 180,
    'admin_actions_read_days' => 180,
    'admin_actions_write_days' => 730,
    'bot_usage_raw_months' => 12,
    'exports_days' => 7,

    'batch_size' => 500,
    'max_batches' => 200,
    'batch_sleep_ms' => 50,

    'erasure_due_days' => 30,
    'erasure_warn_days' => 20,
    // بصمة الرقم في سجل الحذف؛ مفتاح مستقل يبقيها ثابتة عند تدوير APP_KEY
    'erasure_hmac_key' => env('ERASURE_HMAC_KEY') ?: env('APP_KEY'),
    // سجل محو للإلحاق فقط خارج القاعدة: لا تمحوه الاستعادة، ومنه يُعاد تطبيق الحذف بعدها (A8)
    'erasure_ledger' => storage_path('app/private/erasure-ledger.jsonl'),
    // ما حذفه prune وforget منذ آخر نسخة: تطرحه مقارنة الانكماش في backup:run
    'shrink_ledger' => storage_path('app/private/retention-shrink.json'),

    'prunable_models' => [
        AuditLog::class,
        Notification::class,
        WaAdminAction::class,
        WaBotUsage::class,
        // E22: أحداث حساب ميتا المعلوماتية بعد 180 يوماً (الحرج والتحذير يبقيان)
        WaAccountEvent::class,
    ],
];
