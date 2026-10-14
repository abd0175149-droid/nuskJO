<?php

namespace App\Services\WhatsApp\Media;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Services\Retention\ConversationEraser;
use App\Services\WhatsApp\ReplyState;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * إشعار حفظ الملفات وخيار العميل (E14.10، خلف العلم media_consent_prompt): بعد أول ملف يُحفظ، رسالة بزرين «موافق»
 * و«احذف ملفاتي» مرة كل 30 يوماً على الأكثر وداخل النافذة. الضغط لا يمر بالبوت. الرفض يحذف المرفقات وحدها فوراً
 * (ومن ميتا لما لم ينتهِ)، ويوقف أرشفة ما بعده، ويحوّل المحادثة لموظف يرتّب استلام الوثائق بطريقة أخرى.
 */
final class MediaConsent
{
    public const YES = 'media_consent:yes';

    public const NO = 'media_consent:no';

    private const NOTICE_DAYS = 30;

    public const REFUSED_REPLY = 'حذفنا الملفات التي أرسلتها. سيتواصل معك موظف لترتيب استلام الوثائق بطريقة أخرى.';

    /** نص يعتمده المالك (قانون حماية البيانات الشخصية رقم 24 لسنة 2023) */
    public static function noticeText(): string
    {
        $days = (int) config('whatsapp.media.retention_days');
        $privacy = preg_replace('#^https?://#', '', rtrim((string) config('app.url'), '/')).'/privacy';

        return "وصلنا ملفك. نحفظ الوثائق التي ترسلها لإتمام حجزك فقط، ويطّلع عليها الموظف المختص، وتُحذف تلقائياً بعد {$days} يوماً. التفاصيل: {$privacy}";
    }

    /** yes | no | null */
    public static function choice(?string $buttonId): ?string
    {
        return match ($buttonId) {
            self::YES => 'yes',
            self::NO => 'no',
            default => null,
        };
    }

    /** داخل معاملة الوارد */
    public static function record(WaConversation $conv, string $choice): void
    {
        if (!Schema::hasColumn('wa_conversations', 'media_consent')) {
            return;
        }
        $conv->forceFill(['media_consent' => $choice === 'yes' ? 'granted' : 'refused', 'media_consent_at' => now()])->save();
        AuditLog::log(AuditAction::WaMediaConsent, 'wa_conversation', $conv->id, null, ['choice' => $choice]);
    }

    /** بعد الاعتماد: الحذف ونداءات ميتا والرد خارج المعاملة */
    public static function refused(WaConversation $conv): void
    {
        $counts = app(ConversationEraser::class)->eraseMedia($conv);
        AuditLog::safe(AuditAction::WaMediaPurge, 'wa_conversation', $conv->id, null, ['mode' => 'consent'] + $counts);
        WhatsAppChannel::sendText($conv, self::REFUSED_REPLY, 'system');
        ReplyState::markUnserved($conv->fresh(), 'media_refused');
    }

    /** بعد حفظ ملف: الإشعار إن لم يُرسل خلال 30 يوماً ولم يختر العميل بعد. true إن أُرسل */
    public static function maybeNotify(WaConversation $conv): bool
    {
        if (!FeatureFlags::on('media_consent_prompt') || $conv->media_consent !== null
            || ($conv->media_notice_at && $conv->media_notice_at->gt(now()->subDays(self::NOTICE_DAYS)))
            || $conv->is_admin_chat || WaAdminNumber::forPhone($conv->phone) || !$conv->isWindowOpen()
            || !Cache::add("wa:media:notice:{$conv->id}", 1, 300)) {
            return false;
        }
        $res = WhatsAppChannel::sendButtons($conv, self::noticeText(), [
            ['id' => self::YES, 'title' => 'موافق'],
            ['id' => self::NO, 'title' => 'احذف ملفاتي'],
        ], 'system');
        if ($res['ok'] ?? false) {
            $conv->forceFill(['media_notice_at' => now()])->save();
        }

        return (bool) ($res['ok'] ?? false);
    }
}
