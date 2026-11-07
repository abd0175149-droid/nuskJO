<?php

use App\Services\WhatsApp\OpenWa\UnknownNoticeCleanup;
use Illuminate\Database\Migrations\Migration;

/**
 * مرة واحدة: إشعارات التشفير ورمز الأمان التي خُزّنت «⚠️ رسالة لا يعرضها واتساب ويب» عند ربط الرقمين في
 * 2026-11-07، قبل أن يعرف المحلل rawType (UnknownNoticeCleanup). بلا down: الحذف لا يُعاد، والنسخة قبل النشر.
 */
return new class extends Migration
{
    public function up(): void
    {
        UnknownNoticeCleanup::run();
    }

    public function down(): void {}
};
