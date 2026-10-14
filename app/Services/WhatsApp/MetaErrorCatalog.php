<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Services\Ops\OpsAlerts;
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

    private const PERMISSION = 'صلاحيات رمز ميتا لا تسمح بهذه العملية — راجع صلاحيات مستخدم النظام والتطبيق في لوحة ميتا.';

    /** رمز ميتا => [رمزنا، النص المحفوظ مع الرسالة، الفعل]. والنطاق 200–299 (صلاحيات) في entry() */
    public const CODES = [
        190 => ['TOKEN_INVALID', 'رمز ميتا منتهٍ أو مُلغى — يحدّثه المدير من إعدادات الواتساب.', 'alert'],
        0 => ['TOKEN_INVALID', 'خطأ اعتماد عند ميتا — تحقق من رمز الوصول في إعدادات الواتساب.', 'alert'],
        3 => ['PERMISSION_DENIED', 'التطبيق لا يملك قدرة هذه العملية عند ميتا — راجع إعداد التطبيق.', 'alert'],
        10 => ['PERMISSION_DENIED', self::PERMISSION, 'alert'],
        131005 => ['PERMISSION_DENIED', self::PERMISSION, 'alert'],
        131042 => ['PAYMENT_ISSUE', 'مشكلة في وسيلة الدفع في حساب ميتا — راجع الفوترة في WhatsApp Manager.', 'alert'],
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

    /** رموز الاعتماد والقيود: تنبيه فوري للمدير وسطر «آخر خطأ اعتماد» في بطاقة الصحة (E08) */
    private const ACCOUNT_ERRORS = [0, 3, 10, 190, 368, 131005, 131031, 131042];

    /** عنوان التنبيه حسب الرمز (والنص «[الرمز] الوصف») */
    private const ALERT_TITLES = [
        0 => '⛔ رمز ميتا منتهٍ أو ملغى — الإرسال متوقف',
        190 => '⛔ رمز ميتا منتهٍ أو ملغى — الإرسال متوقف',
        368 => '⛔ الحساب مقيّد من ميتا',
        131031 => '⛔ الحساب مقيّد من ميتا',
        131042 => '💳 مشكلة دفع في حساب ميتا',
        3 => '⛔ صلاحيات ميتا ناقصة',
        10 => '⛔ صلاحيات ميتا ناقصة',
        131005 => '⛔ صلاحيات ميتا ناقصة',
    ];

    /** @return array{0: string, 1: string, 2: ?string}|null */
    private static function entry(?int $metaCode): ?array
    {
        if ($metaCode === null) {
            return null;
        }
        if (isset(self::CODES[$metaCode])) {
            return self::CODES[$metaCode];
        }

        return $metaCode >= 200 && $metaCode <= 299 ? ['PERMISSION_DENIED', self::PERMISSION, 'alert'] : null;
    }

    public static function isAccountError(?int $metaCode): bool
    {
        return $metaCode !== null && (in_array($metaCode, self::ACCOUNT_ERRORS, true) || ($metaCode >= 200 && $metaCode <= 299));
    }

    public static function codeFor(?int $metaCode): string
    {
        return self::entry($metaCode)[0] ?? 'META_ERROR';
    }

    public static function describe(?int $metaCode): ?string
    {
        return self::entry($metaCode)[1] ?? null;
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
        $entry = self::entry($metaCode);
        if ($entry === null) {
            return;
        }
        $action = $entry[2];

        if ($metaCode === 190) {
            Cache::put('wa:last_auth_error_at', now()->toIso8601String(), now()->addDays(2));
        }
        if (self::isAccountError($metaCode)) {
            Cache::put('wa:last_account_error', ['code' => $metaCode, 'at' => now()->getTimestamp()], now()->addDay());
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

        // مرة في الساعة لكل رمز، والدفع للجهاز مباشرة (OpsAlerts). الرمز ووصفه فقط، بلا رقم عميل ولا نص
        if ($action === 'alert' || $action === 'suspend') {
            app(OpsAlerts::class)->notifyAdmins('meta:send:'.$metaCode, self::ALERT_TITLES[$metaCode] ?? (self::isAccountError($metaCode) ? '⛔ صلاحيات ميتا ناقصة' : '⚠️ تنبيه من ميتا'), "[{$metaCode}] ".$entry[1]);
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
