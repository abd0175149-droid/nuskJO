<?php

namespace App\Services\WhatsApp\Media;

use App\Jobs\ArchiveWhatsAppMedia;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * جدولة أرشفة المرفق عند الاستلام (E14.3)، داخل معاملة الوارد: الوظيفة تُرسل بعد الاعتماد وحده.
 * العلم مطفأ = السلوك القديم (نص نائب، media_status فارغ). لا أرشفة لمحادثات الإدارة، ولا لمن اختار «احذف ملفاتي».
 */
final class MediaIntake
{
    /** ملف أكبر من 100MB أو تعذّر تنزيله عند ميتا: يصل بهذا الرمز بدل المعرّف */
    public const META_TOO_LARGE = 131052;

    private static ?bool $hasColumns = null;

    public static function onInbound(WaMessage $message, WaConversation $conv, array $msg): void
    {
        self::$hasColumns ??= Schema::hasColumn('wa_messages', 'media_status');
        if (!self::$hasColumns || !FeatureFlags::on('media_archive')) {
            return;
        }

        // الموظف يرى السبب ويطلب نسخة أصغر أو الاستلام في المكتب. لا رسالة آلية
        if ((int) data_get($msg, 'errors.0.code') === self::META_TOO_LARGE) {
            $message->update(['media_status' => 'failed', 'media_error' => (string) self::META_TOO_LARGE]);

            return;
        }
        if (!$message->media_id || !in_array($message->msg_type, (array) config('whatsapp.media.types', []), true)) {
            return;
        }
        if ($conv->is_admin_chat || WaAdminNumber::forPhone($conv->phone)) {
            return;
        }
        if ($conv->media_consent === 'refused') {
            $message->update(['media_status' => 'skipped_consent']);

            return;
        }
        if (Cache::get('wa:media:quota_exceeded')) {
            $message->update(['media_status' => 'skipped_quota']);
            MediaArchiver::alertOnce('quota', '⚠️ أرشيف المرفقات بلغ سقفه', 'لم تعد المرفقات الجديدة تُحفظ حتى يُرفع السقف (WA_MEDIA_QUOTA_MB) أو تُقلَّم القديمة.');

            return;
        }

        $message->update(['media_status' => 'pending']);
        ArchiveWhatsAppMedia::dispatch($message->id)->afterCommit();
    }
}
