<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use Illuminate\Support\Facades\Cache;

/**
 * جدول رموز أخطاء ميتا الوحيد: الرمز الداخلي، والنص العربي الذي يراه الموظف، وما يُفعل عنده.
 * يأتي الرمز من ردّ الإرسال (WhatsAppChannel) ومن حالات التسليم في الويبهوك (WhatsAppInbox::handleStatus).
 * E18 توسّع هذا الجدول ولا تستبدله.
 *
 * الأفعال: null لا شيء · alert تنبيه المدير (مرة في الساعة لكل رمز) · suspend إيقاف الإرسال ثم تنبيه،
 * فقط إن كان الرمز في services.whatsapp.autosuspend_codes (الافتراضي 368 وحده: 131031 قد يعني PIN خاطئاً).
 */
final class MetaErrorCatalog
{
    private const RATE = 'تجاوزنا حد الإرسال عند ميتا — أعد المحاولة بعد قليل.';

    private const RESTRICTED = 'حساب واتساب مقيّد من ميتا — راجع حالة الحساب في لوحة ميتا.';

    /** رمز ميتا => [رمزنا، النص المحفوظ مع الرسالة، الفعل] */
    public const CODES = [
        190 => ['TOKEN_INVALID', 'رمز ميتا منتهٍ أو مُلغى — يحدّثه المدير من إعدادات الواتساب.', 'alert'],
        131047 => ['WINDOW_EXPIRED', 'انتهت نافذة الـ24 ساعة عند ميتا — لا يمكن الإرسال حتى يراسلنا العميل.', null],
        131026 => ['UNDELIVERABLE', 'تعذّر التسليم: الرقم بلا واتساب، أو بنسخة قديمة، أو لم يقبل الشروط.', null],
        131051 => ['UNSUPPORTED', 'نوع الرسالة غير مدعوم في واتساب.', null],
        130429 => ['RATE_LIMITED', self::RATE, null],
        131056 => ['RATE_LIMITED', self::RATE, null],
        4 => ['RATE_LIMITED', self::RATE, null],
        80007 => ['RATE_LIMITED', self::RATE, null],
        131031 => ['ACCOUNT_RESTRICTED', self::RESTRICTED, 'alert'],
        368 => ['ACCOUNT_RESTRICTED', self::RESTRICTED, 'suspend'],
        131048 => ['SPAM_RESTRICTED', 'تقييد بسبب بلاغات السبام — راجع جودة الرقم في لوحة ميتا.', 'alert'],
        100 => ['META_ERROR', 'معامل غير صالح في الطلب.', null],
        131052 => ['MEDIA_ERROR', 'خطأ في تنزيل الوسائط.', null],
        131053 => ['MEDIA_ERROR', 'خطأ في رفع الوسائط.', null],
    ];

    public static function codeFor(?int $metaCode): string
    {
        return self::CODES[$metaCode][0] ?? 'META_ERROR';
    }

    public static function describe(?int $metaCode): ?string
    {
        return self::CODES[$metaCode][1] ?? null;
    }

    /** النص المحفوظ: «[رمز] النص العربي» أو «[رمز] نص ميتا» لما لا يعرفه الجدول */
    public static function message(?int $metaCode, string $fallback): string
    {
        if ($metaCode === null) {
            return $fallback;
        }

        return "[{$metaCode}] ".(self::describe($metaCode) ?? $fallback);
    }

    public static function react(?int $metaCode): void
    {
        if ($metaCode === null || !isset(self::CODES[$metaCode])) {
            return;
        }
        $action = self::CODES[$metaCode][2];

        if ($metaCode === 190) {
            Cache::put('wa:last_auth_error_at', now()->toIso8601String(), now()->addDays(2));
        }

        if ($action === 'suspend' && self::autosuspends($metaCode)) {
            $settings = WaBotSetting::current();
            if (!$settings->wa_suspended) {
                $settings->update(['wa_suspended' => true]);
                WaLog::error('graph.autosuspend', ['meta_code' => $metaCode]);
                WhatsAppInbox::notifyAdmins('⛔ إيقاف إرسال الواتساب', "ردّت ميتا بالرمز {$metaCode} (تقييد الحساب)، فأُوقف كل الإرسال تلقائياً. راجع حالة الحساب قبل إعادة التشغيل.", '/whatsapp/settings');
            }

            return;
        }

        if (($action === 'alert' || $action === 'suspend') && Cache::add("wa:alert:{$metaCode}", 1, 3600)) {
            WhatsAppInbox::notifyAdmins('⚠️ تنبيه من ميتا', "[{$metaCode}] ".self::CODES[$metaCode][1], '/whatsapp/settings');
        }
    }

    private static function autosuspends(int $metaCode): bool
    {
        if (!config('services.whatsapp.health_autosuspend')) {
            return false;
        }
        $codes = array_map('intval', array_filter(array_map('trim', explode(',', (string) config('services.whatsapp.autosuspend_codes')))));

        return in_array($metaCode, $codes, true);
    }
}
