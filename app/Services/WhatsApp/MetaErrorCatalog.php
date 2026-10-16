<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Services\Ops\OpsAlerts;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Cache;

/**
 * جدول رموز أخطاء ميتا الوحيد في المستودع (E06 بنته، وE18 وسّعته ولم تستبدله).
 *
 * لكل رمز: الرمز الداخلي (error_code)، والفئة (error_category)، والنطاق (القناة كلها أو هذا العميل أو هذه الرسالة)،
 * والرسالة العربية للموظف، والإجراء المقترح، وسياسة الإعادة (تنفذها E19)، وهل ينبّه المدير.
 * meta_code رقم ميتا وحده، وerror_code رمز من هذا الجدول وحده (ومعه رموز ما قبل ميتا والشبكة وPOLICY_*).
 *
 * جرد E18.1 (ما وُجد فأُعيد توجيهه هنا ولم يُكرَّر): لا MetaErrors.php منفصل (جدول E06 هو هذا الملف)، وgraphError()
 * في WhatsAppChannel تقرأ الشكل وحده، وisAccountError هنا لا في MetaHealth، وWaLog بقائمة مفاتيح مسموحة،
 * والأعمدة meta_code وfbtrace_id (E06) وerror_code (E11) موجودة، و'unconfirmed' (E12) صارت 'unknown' في E19.
 *
 * وضع الظل: علم meta_error_v2 مطفأ يحسب التصنيف ويكتب error_category وpayload._meta_error والسجل، لكن الرسالة
 * المعروضة والرمز المعاد والتنبيه والإيقاف من الجدول القديم (LEGACY) كما كانت. يُحذف LEGACY مع إزالة العلم.
 *
 * الإيقاف الآلي (A10): 368 وحده افتراضياً (services.whatsapp.autosuspend_codes)، و131031 قد يعني PIN خاطئاً فينبّه فقط.
 * ولا رفع آلياً لأي قفل.
 */
final class MetaErrorCatalog
{
    /** الإصدار الذي رُوجع عليه الجدول: ترقية graph_version تفرض مراجعته وتحديث هذا الثابت في التغيير نفسه */
    public const REVIEWED_FOR_VERSION = 'v25.0';

    public const DOC_URL = 'https://developers.facebook.com/documentation/business-messaging/whatsapp/support/error-codes';

    public const STATUS_URL = 'https://metastatus.com';

    /** الفئة => [سياسة الإعادة، تنبيه المدير، النطاق الافتراضي] */
    public const CATEGORIES = [
        'transient' => ['once', false, 'message'],
        'throttle' => ['backoff_4x', false, 'channel'],
        'window' => ['none', false, 'recipient'],
        'auth' => ['none', true, 'channel'],
        'integrity' => ['none', true, 'channel'],
        'quality' => ['after_24h', false, 'recipient'],
        'caller' => ['none', false, 'message'],
        'unknown' => ['none', false, 'message'],
    ];

    /** لا إعادة أبداً أياً كانت الفئة، ومعها كل رموز auth (يُفحص قبل أي إعادة) */
    public const NEVER_RETRY = [131047, 131026, 368, 131031, 131048, 131049];

    private const TRANSIENT = 'خلل مؤقت عند ميتا. ستُعاد المحاولة مرة واحدة.';

    private const TRANSIENT_ACTION = 'إن تكرر فراجع صفحة حالة منصة ميتا قبل إعادة الإرسال.';

    private const RATE = 'تجاوزنا حد الإرسال عند ميتا. ستُعاد المحاولة بعد مهلة متزايدة.';

    private const RATE_ACTION = 'انتظر قليلاً؛ وإن تكرر فخفّف الرسائل الآلية المتتالية.';

    private const PERMISSION = 'صلاحيات رمز ميتا لا تسمح بهذه العملية.';

    private const PERMISSION_ACTION = 'راجع صلاحيات مستخدم النظام والتطبيق في Business Settings (whatsapp_business_messaging وwhatsapp_business_management).';

    private const QUALITY_ACTION = 'راجع Account Quality في WhatsApp Manager. لا تُرسل رسائل جماعية.';

    private const PARAM = 'طلب غير صالح إلى ميتا (معامل ناقص أو خاطئ).';

    private const PARAM_ACTION = 'أبلغ المسؤول إن تكرر مع نوع الرسالة نفسه.';

    private const MEDIA = 'تعذّر على ميتا جلب الوسائط أو رفعها.';

    /** رمز ميتا => [رمزنا، الفئة، النطاق (null = افتراضي الفئة)، الرسالة للموظف، الإجراء]. النطاقان 200–299 و132000–132999 في classify */
    public const CODES = [
        // transient: إعادة واحدة
        1 => ['META_TRANSIENT', 'transient', null, self::TRANSIENT, self::TRANSIENT_ACTION],
        2 => ['META_TRANSIENT', 'transient', null, self::TRANSIENT, self::TRANSIENT_ACTION],
        131000 => ['META_TRANSIENT', 'transient', null, self::TRANSIENT, self::TRANSIENT_ACTION],
        131016 => ['META_TRANSIENT', 'transient', null, self::TRANSIENT, self::TRANSIENT_ACTION],
        133004 => ['META_TRANSIENT', 'transient', null, self::TRANSIENT, self::TRANSIENT_ACTION],
        // throttle: مهلة 4^X (E19). 131056 حد الزوج لعميل واحد، فلا يمس القناة
        4 => ['RATE_LIMITED', 'throttle', null, self::RATE, self::RATE_ACTION],
        80007 => ['RATE_LIMITED', 'throttle', null, self::RATE, self::RATE_ACTION],
        130429 => ['RATE_LIMITED', 'throttle', null, self::RATE, self::RATE_ACTION],
        131056 => ['PAIR_RATE_LIMITED', 'throttle', 'recipient', 'أُرسلت رسائل كثيرة لهذا العميل بسرعة. انتظر ثواني ثم أعد.', 'انتظر ثواني قبل الرسالة التالية لهذا العميل.'],
        // window
        131047 => ['WINDOW_EXPIRED', 'window', null, 'انتهت نافذة الـ24 ساعة. لا يمكن مراسلة العميل حتى يكتب لنا (لا قوالب بقرار المالك).', 'انتظر رسالة جديدة من العميل.'],
        // auth: القناة كلها، تنبيه فوري
        0 => ['TOKEN_INVALID', 'auth', null, 'رمز ميتا منتهٍ أو مُلغى — الإرسال متوقف.', 'ولّد رمز System User جديداً من Business Settings وأدخله في إعدادات الواتساب.'],
        190 => ['TOKEN_INVALID', 'auth', null, 'رمز ميتا منتهٍ أو مُلغى — الإرسال متوقف.', 'ولّد رمز System User جديداً من Business Settings وأدخله في إعدادات الواتساب.'],
        3 => ['PERMISSION_DENIED', 'auth', null, 'التطبيق لا يملك قدرة هذه العملية عند ميتا.', 'راجع إعداد التطبيق ومنتج WhatsApp في App Dashboard.'],
        10 => ['PERMISSION_DENIED', 'auth', null, self::PERMISSION, self::PERMISSION_ACTION],
        131005 => ['PERMISSION_DENIED', 'auth', null, self::PERMISSION, self::PERMISSION_ACTION],
        133010 => ['CHANNEL_NOT_REGISTERED', 'auth', null, 'رقم الشركة نفسه غير مسجّل في Cloud API.', 'سجّل الرقم من WhatsApp Manager ثم أعد المحاولة.'],
        131042 => ['BILLING_ISSUE', 'auth', null, 'مشكلة في وسيلة الدفع أو الأهلية في حساب ميتا — رسائل الخدمة تتوقف بعد الحصة المجانية.', 'راجع Payment settings وحد الإنفاق في WhatsApp Manager.'],
        // integrity: تنبيه، والإيقاف الآلي لـ 368 وحده (autosuspend_codes)
        368 => ['ACCOUNT_RESTRICTED', 'integrity', null, 'حساب واتساب مقيّد من ميتا لمخالفة السياسات.', self::QUALITY_ACTION],
        131031 => ['ACCOUNT_LOCKED', 'integrity', null, 'حساب واتساب مقفل عند ميتا (قد يكون رمز التحقق بخطوتين خاطئاً).', 'راجع Account Quality والتحقق بخطوتين في WhatsApp Manager.'],
        131048 => ['SPAM_LIMITED', 'integrity', null, 'تقييد بسبب بلاغات السبام على الرقم.', self::QUALITY_ACTION],
        // quality: هذا العميل وحده
        131049 => ['MARKETING_LIMITED', 'quality', null, 'ميتا حدّت الرسائل لهذا العميل حفاظاً على تجربته.', 'لا تُعد الإرسال الآن؛ انتظر أن يكتب العميل.'],
        131050 => ['MARKETING_OPTED_OUT', 'quality', null, 'العميل أوقف رسائل التسويق من الشركة.', 'لا تُرسل له رسائل تسويقية.'],
        // caller: هذا الطلب أو هذا العميل
        100 => ['BAD_REQUEST', 'caller', null, self::PARAM, self::PARAM_ACTION],
        131008 => ['BAD_PARAMETER', 'caller', null, self::PARAM, self::PARAM_ACTION],
        131009 => ['BAD_PARAMETER', 'caller', null, self::PARAM, self::PARAM_ACTION],
        131021 => ['SELF_RECIPIENT', 'caller', 'recipient', 'لا يمكن مراسلة رقم الشركة نفسه.', 'تحقق من رقم العميل في المحادثة.'],
        131026 => ['RECIPIENT_UNREACHABLE', 'caller', 'recipient', 'تعذّر التسليم: غالباً تطبيق العميل قديم أو لم يقبل شروط واتساب الأخيرة.', 'اطلب من العميل تحديث التطبيق أو تواصل معه بطريقة أخرى.'],
        130497 => ['COUNTRY_RESTRICTED', 'caller', 'recipient', 'ميتا تمنع المراسلة إلى بلد هذا العميل.', 'تواصل مع العميل بطريقة أخرى.'],
        131051 => ['UNSUPPORTED_TYPE', 'caller', null, 'نوع الرسالة غير مدعوم في واتساب.', 'أرسل نصاً أو صورة بدلاً منه.'],
        131052 => ['MEDIA_ERROR', 'caller', null, self::MEDIA, 'تحقق من الملف أو الرابط ثم أعد.'],
        131053 => ['MEDIA_ERROR', 'caller', null, self::MEDIA, 'تحقق من الملف أو الرابط ثم أعد.'],
    ];

    /** رموز ما قبل ميتا والشبكة: رمزنا => [الفئة، النطاق، الرسالة، الإجراء، الإعادة] */
    public const LOCAL = [
        'CHANNEL_NOT_CONFIGURED' => ['auth', 'channel', 'قناة الواتساب غير مهيّأة (رمز الوصول أو معرّف الرقم ناقص).', 'يكمل المدير الإعداد من إعدادات الواتساب.', 'none'],
        'GRAPH_CONFIG_INVALID' => ['auth', 'channel', 'إصدار Graph أو معرّف الرقم غير صالح في الإعدادات.', 'يراجع المدير إعدادات الواتساب.', 'none'],
        'SENDING_SUSPENDED' => ['integrity', 'channel', 'الإرسال موقوف حالياً (قفل طارئ).', 'يرفعه المدير من إعدادات الواتساب بعد مراجعة السبب.', 'none'],
        'WINDOW_EXPIRED' => ['window', 'recipient', 'انتهت نافذة الـ24 ساعة. لا يمكن مراسلة العميل حتى يكتب لنا (لا قوالب بقرار المالك).', 'انتظر رسالة جديدة من العميل.', 'none'],
        'NO_RECIPIENT' => ['caller', 'recipient', 'العميل يراسل باسم مستخدم ورقمه غير ظاهر، والرد عليه غير مفعّل بعد.', 'أبلغ المدير.', 'none'],
        'OUT_OF_SCOPE' => ['caller', 'message', 'هذه المحادثة خارج نطاقك.', 'اطلب من المدير إسنادها إليك.', 'none'],
        'BAD_MEDIA_URL' => ['caller', 'message', 'رابط الوسائط يجب أن يكون HTTPS ليقبله واتساب.', 'أبلغ المسؤول.', 'none'],
        'UNSUPPORTED' => ['caller', 'message', 'هذا النوع من الرسائل غير مدعوم.', 'أرسل نصاً بدلاً منه.', 'none'],
        'APPSECRET_PROOF_INVALID' => ['auth', 'channel', 'ميتا رفضت إثبات سر التطبيق (appsecret_proof) — كل النداءات تُرفض.', 'صحّح سر التطبيق في الإعدادات، أو عطّل WA_APPSECRET_PROOF ثم config:cache.', 'none'],
        'HTTP_CONNECT_FAILED' => ['transient', 'channel', 'تعذّر الاتصال بميتا (شبكة)، ولم تُرسل الرسالة.', 'أعد المحاولة بعد قليل؛ وإن تكرر فراجع اتصال الخادم.', 'once'],
        'HTTP_TIMEOUT' => ['transient', 'channel', 'انتهت مهلة ميتا — قد تكون الرسالة وصلت.', 'افتح محادثة العميل وتأكد قبل إعادة الإرسال.', 'none'],
        'HTTP_5XX_NO_BODY' => ['transient', 'channel', 'خلل عند ميتا بلا رد مفهوم — قد تكون الرسالة وصلت.', 'افتح محادثة العميل وتأكد قبل إعادة الإرسال.', 'none'],
        'HTTP_NO_RESPONSE' => ['transient', 'channel', 'انقطع الاتصال بميتا بعد إرسال الطلب — قد تكون الرسالة وصلت.', 'افتح محادثة العميل وتأكد قبل إعادة الإرسال.', 'none'],
        // دفتر الإرسال (E19)
        'OUTCOME_UNKNOWN' => ['transient', 'message', 'قد تكون الرسالة وصلت، ولم تؤكدها ميتا بعد.', 'تحقق من الخيط أو اسأل العميل قبل إعادة الإرسال.', 'none'],
        'SEND_IN_PROGRESS' => ['transient', 'message', 'الرسالة نفسها قيد الإرسال الآن.', 'انتظر ثواني حتى تظهر في الخيط.', 'none'],
        'IDEMPOTENCY_MISMATCH' => ['caller', 'message', 'مفتاح الإرسال مستعمل لنص آخر.', 'اضغط «إرسال» مرة أخرى.', 'none'],
        'REPLY_STALE' => ['caller', 'message', 'أُلغي الرد الآلي لأن المحادثة تغيّرت أثناء الانتظار (ردّ موظف أو أُطفئ البوت).', 'لا شيء: المحادثة مع موظف.', 'none'],
        'POLICY_BLOCKED' => ['caller', 'message', 'حُجبت الرسالة قبل الإرسال بسياسة المحتوى الصادر.', 'راجع الرسالة الآلية في تقرير السياسة.', 'none'],
        // مغلف الإرسال والملفات (E32)
        'EMPTY_MESSAGE' => ['caller', 'message', 'لا يُرسل نص فارغ.', 'اكتب الرسالة ثم أرسلها.', 'none'],
        'MEDIA_ID_MISSING' => ['caller', 'message', 'الملف لم يُرفع إلى ميتا بعد.', 'أرفق الملف من جديد.', 'none'],
        'FILENAME_MISSING' => ['caller', 'message', 'اسم الملف مطلوب للمستند.', 'أرفق الملف من جديد باسمه.', 'none'],
        'MEDIA_REJECTED' => ['caller', 'message', 'الملف مرفوض قبل الإرسال (النوع أو الحجم أو المحتوى).', 'راجع الملف وأرسله من جديد.', 'none'],
        'MEDIA_FILE_MISSING' => ['caller', 'message', 'نسخة الملف المحفوظة لم تعد على الخادم، فلم يُرسل.', 'أرفق الملف من جديد.', 'none'],
        'MEDIA_QUEUE_STALE' => ['transient', 'message', 'تأخر الملف في الطابور فلم يُرسل.', 'أرفقه من جديد إن ما زال مطلوباً.', 'none'],
        'MEDIA_SEND_FAILED' => ['transient', 'message', 'تعذّر إكمال إرسال الملف، ولم يصل العميل.', 'أرفق الملف من جديد؛ وإن تكرر فأبلغ المدير.', 'none'],
        'RECIPIENT_MISMATCH' => ['caller', 'message', 'المحادثة المؤكَّدة لا تطابق المحادثة المفتوحة.', 'أعد فتح المحادثة وأرسل من جديد.', 'none'],
    ];

    // ==================== التصنيف ====================

    /**
     * @return array{code: string, category: string, scope: string, message: string, action: string, retry: string, alert: bool}
     */
    public static function classify(?int $metaCode, ?string $metaMessage = null, ?int $httpStatus = null): array
    {
        // 100 بذكر appsecret_proof: الإثبات مرفوض، لا معامل خاطئ
        if ($metaCode === 100 && str_contains(strtolower((string) $metaMessage), 'appsecret_proof')) {
            return self::fromLocal('APPSECRET_PROOF_INVALID') + ['alert' => true];
        }

        $entry = $metaCode !== null ? (self::CODES[$metaCode] ?? null) : null;
        if ($entry === null && $metaCode !== null && $metaCode >= 200 && $metaCode <= 299) {
            $entry = self::CODES[10];
        }
        if ($entry === null && $metaCode !== null && $metaCode >= 132000 && $metaCode <= 132999) {
            $entry = ['TEMPLATE_ERROR', 'caller', null, 'خطأ في قالب رسالة (لا قوالب في النظام).', 'أبلغ المسؤول.'];
        }
        // 5xx بجسم JSON برمز لا يعرفه الجدول: خلل مؤقت
        if ($entry === null && $httpStatus !== null && $httpStatus >= 500) {
            $entry = self::CODES[131000];
        }
        if ($entry === null) {
            $label = $metaCode !== null ? "رمز {$metaCode}" : ($httpStatus !== null ? "HTTP {$httpStatus}" : 'بلا رمز');
            $entry = ['META_ERROR', 'unknown', null, "رفضت ميتا الرسالة ({$label}). أُبلغ المسؤول.", 'إن تكرر فراجع عدّاد الرموز المجهولة في whatsapp:doctor.'];
        }

        [$code, $category, $scope, $message, $action] = $entry;
        [$retry, $alert, $defaultScope] = self::CATEGORIES[$category];
        if (self::neverRetry($metaCode, $category)) {
            $retry = 'none';
        }

        return ['code' => $code, 'category' => $category, 'scope' => $scope ?? $defaultScope, 'message' => $message, 'action' => $action, 'retry' => $retry, 'alert' => $alert];
    }

    /**
     * معنى رمز داخلي محفوظ (error_code) للعرض: من LOCAL، ثم من CODES، ثم POLICY_*، وإلا null.
     *
     * @return array{code: string, category: string, scope: string, message: string, action: string, retry: string}|null
     */
    public static function byCode(?string $code): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }
        if (isset(self::LOCAL[$code])) {
            return self::fromLocal($code);
        }
        foreach (self::CODES as $metaCode => $entry) {
            if ($entry[0] === $code) {
                return array_diff_key(self::classify($metaCode), ['alert' => 1]);
            }
        }
        if (str_starts_with($code, 'POLICY_')) {
            return ['code' => $code] + array_diff_key(self::fromLocal('POLICY_BLOCKED'), ['code' => 1]);
        }
        if ($code === 'TEMPLATE_ERROR') {
            return array_diff_key(self::classify(132000), ['alert' => 1]);
        }

        return $code === 'META_ERROR' ? array_diff_key(self::classify(null), ['alert' => 1]) : null;
    }

    /** للعرض: الفئة والإجراء لرسالة فاشلة محفوظة. رقم ميتا أولاً، ثم الرمز الداخلي */
    public static function forStored(?int $metaCode, ?string $errorCode, ?string $category = null): ?array
    {
        $entry = $metaCode !== null ? self::classify($metaCode) : self::byCode($errorCode);
        if ($entry === null) {
            return $category ? ['category' => $category, 'action' => null] : null;
        }

        return ['category' => $category ?: $entry['category'], 'action' => $entry['action']];
    }

    public static function neverRetry(?int $metaCode, ?string $category = null): bool
    {
        return in_array($metaCode, self::NEVER_RETRY, true) || ($category ?? ($metaCode !== null ? self::classify($metaCode)['category'] : null)) === 'auth';
    }

    /** خنق من ميتا (إعادة مؤجلة تستحق المحاولة) */
    public static function isThrottle(?int $metaCode): bool
    {
        return $metaCode !== null && self::classify($metaCode)['category'] === 'throttle';
    }

    /** أخطاء الاعتماد والقيود (auth وintegrity): تنبيه فوري وسطر «آخر خطأ اعتماد» في بطاقة الصحة (E08) */
    public static function isAccountError(?int $metaCode): bool
    {
        return $metaCode !== null && in_array(self::classify($metaCode)['category'], ['auth', 'integrity'], true);
    }

    /** details من ميتا: 300 حرف على الأكثر، وكل تتابع 7 أرقام فأكثر يُقنَّع (لا trim بقائمة محارف: يتلف العربية) */
    public static function maskDetails(?string $details): ?string
    {
        if ($details === null || $details === '') {
            return null;
        }

        return preg_replace('/\d{7,}/u', '•••', mb_substr($details, 0, 300)) ?? null;
    }

    public static function v2(): bool
    {
        return FeatureFlags::on('meta_error_v2');
    }

    private static function fromLocal(string $code): array
    {
        [$category, $scope, $message, $action, $retry] = self::LOCAL[$code];

        return ['code' => $code, 'category' => $category, 'scope' => $scope, 'message' => $message, 'action' => $action, 'retry' => $retry];
    }

    // ==================== المسار القديم (وضع الظل، يُحذف مع علم meta_error_v2) ====================

    private const LEGACY_RATE = 'تجاوزنا حد الإرسال عند ميتا — أعد المحاولة بعد قليل.';

    private const LEGACY_RESTRICTED = 'حساب واتساب مقيّد من ميتا — راجع حالة الحساب في لوحة ميتا.';

    private const LEGACY_PERMISSION = 'صلاحيات رمز ميتا لا تسمح بهذه العملية — راجع صلاحيات مستخدم النظام والتطبيق في لوحة ميتا.';

    /**
     * @deprecated نصوص E06 ورموزها كما يراها الموظف اليوم؛ تبقى ما دام meta_error_v2 مطفأ.
     * رمز ميتا => [رمزنا القديم، النص، الفعل]. والنطاق 200–299 في legacyEntry()
     */
    private const LEGACY = [
        190 => ['TOKEN_INVALID', 'رمز ميتا منتهٍ أو مُلغى — يحدّثه المدير من إعدادات الواتساب.', 'alert'],
        0 => ['TOKEN_INVALID', 'خطأ اعتماد عند ميتا — تحقق من رمز الوصول في إعدادات الواتساب.', 'alert'],
        3 => ['PERMISSION_DENIED', 'التطبيق لا يملك قدرة هذه العملية عند ميتا — راجع إعداد التطبيق.', 'alert'],
        10 => ['PERMISSION_DENIED', self::LEGACY_PERMISSION, 'alert'],
        131005 => ['PERMISSION_DENIED', self::LEGACY_PERMISSION, 'alert'],
        131042 => ['PAYMENT_ISSUE', 'مشكلة في وسيلة الدفع في حساب ميتا — راجع الفوترة في WhatsApp Manager.', 'alert'],
        131047 => ['WINDOW_EXPIRED', 'انتهت نافذة الـ24 ساعة عند ميتا — لا يمكن الإرسال حتى يراسلنا العميل.', null],
        131026 => ['UNDELIVERABLE', 'تعذّر التسليم: الرقم بلا واتساب، أو بنسخة قديمة، أو لم يقبل الشروط.', null],
        131051 => ['UNSUPPORTED', 'نوع الرسالة غير مدعوم في واتساب.', null],
        130429 => ['RATE_LIMITED', self::LEGACY_RATE, null],
        131056 => ['RATE_LIMITED', self::LEGACY_RATE, null],
        4 => ['RATE_LIMITED', self::LEGACY_RATE, null],
        80007 => ['RATE_LIMITED', self::LEGACY_RATE, null],
        131031 => ['ACCOUNT_RESTRICTED', self::LEGACY_RESTRICTED, 'alert'],
        368 => ['ACCOUNT_RESTRICTED', self::LEGACY_RESTRICTED, 'suspend'],
        131048 => ['SPAM_RESTRICTED', 'تقييد بسبب بلاغات السبام — راجع جودة الرقم في لوحة ميتا.', 'alert'],
        100 => ['META_ERROR', 'معامل غير صالح في الطلب.', null],
        131052 => ['MEDIA_ERROR', 'خطأ في تنزيل الوسائط.', null],
        131053 => ['MEDIA_ERROR', 'خطأ في رفع الوسائط.', null],
    ];

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
    private static function legacyEntry(?int $metaCode): ?array
    {
        if ($metaCode === null) {
            return null;
        }
        if (isset(self::LEGACY[$metaCode])) {
            return self::LEGACY[$metaCode];
        }

        return $metaCode >= 200 && $metaCode <= 299 ? ['PERMISSION_DENIED', self::LEGACY_PERMISSION, 'alert'] : null;
    }

    /** @deprecated المسار القديم: الرمز الداخلي القديم */
    public static function codeFor(?int $metaCode): string
    {
        return self::legacyEntry($metaCode)[0] ?? 'META_ERROR';
    }

    /** @deprecated المسار القديم */
    public static function describe(?int $metaCode): ?string
    {
        return self::legacyEntry($metaCode)[1] ?? null;
    }

    /** @deprecated المسار القديم: «[رمز] النص العربي» أو «[رمز] نص ميتا» لما لا يعرفه الجدول */
    public static function message(?int $metaCode, string $fallback): string
    {
        if ($metaCode === null) {
            return $fallback;
        }

        return "[{$metaCode}] ".(self::describe($metaCode) ?? $fallback);
    }

    /** عنوان التنبيه للمسارين */
    public static function alertTitle(?int $metaCode, string $category): string
    {
        return self::ALERT_TITLES[$metaCode] ?? match ($metaCode) {
            131048 => '⚠️ تقييد سبام على رقم الواتساب',
            133010 => '⛔ رقم الشركة غير مسجّل في Cloud API',
            default => null,
        } ?? match ($category) {
            'auth' => '⛔ خطأ اعتماد من ميتا',
            'integrity' => '⛔ تقييد من ميتا على الحساب',
            'throttle' => '⚠️ ميتا تخنق الإرسال',
            default => '⚠️ تنبيه من ميتا',
        };
    }

    /** @deprecated المسار القديم: فعل الجدول القديم (تنبيه أو إيقاف). الجديد في MetaErrorReactor */
    public static function react(?int $metaCode): void
    {
        $entry = self::legacyEntry($metaCode);
        if ($entry === null) {
            return;
        }
        $action = $entry[2];

        self::rememberAccountError($metaCode);

        if ($action === 'suspend' && self::autosuspends($metaCode)) {
            self::suspend($metaCode, 'graph_error');

            return;
        }

        // مرة في الساعة لكل رمز، والدفع للجهاز مباشرة (OpsAlerts). الرمز ووصفه فقط، بلا رقم عميل ولا نص
        if ($action === 'alert' || $action === 'suspend') {
            app(OpsAlerts::class)->notifyAdmins('meta:send:'.$metaCode, self::ALERT_TITLES[$metaCode] ?? (self::isAccountError($metaCode) ? '⛔ صلاحيات ميتا ناقصة' : '⚠️ تنبيه من ميتا'), "[{$metaCode}] ".$entry[1]);
        }
    }

    // ==================== مشترك بين المسارين ====================

    /** آخر خطأ اعتماد لبطاقة الصحة (E08) ووقت آخر 190 لـ whatsapp:doctor */
    public static function rememberAccountError(?int $metaCode): void
    {
        if ($metaCode === 190 || $metaCode === 0) {
            Cache::put('wa:last_auth_error_at', now()->toIso8601String(), now()->addDays(2));
        }
        if (self::isAccountError($metaCode)) {
            Cache::put('wa:last_account_error', ['code' => $metaCode, 'at' => now()->getTimestamp()], now()->addDay());
        }
    }

    public static function autosuspends(?int $metaCode): bool
    {
        if ($metaCode === null || !config('services.whatsapp.health_autosuspend')) {
            return false;
        }
        $codes = array_map('intval', array_filter(array_map('trim', explode(',', (string) config('services.whatsapp.autosuspend_codes')))));

        return in_array($metaCode, $codes, true);
    }

    /** إيقاف آلي واحد مع تدقيق آمن وتنبيه. لا رفع آلياً أبداً */
    public static function suspend(int $metaCode, string $source): bool
    {
        $settings = WaBotSetting::current();
        if ($settings->wa_suspended) {
            return false;
        }
        \App\Support\AuditActor::as('system', 'graph:'.$metaCode, fn () => Settings\SettingsWriter::apply([
            'wa_suspended' => true, 'wa_suspended_at' => now(), 'wa_suspended_by' => null,
            'wa_suspended_source' => $source, 'wa_suspended_reason' => 'رمز ميتا '.$metaCode,
        ], null, \App\Enums\AuditAction::WaSuspendAuto, ['meta_code' => $metaCode], audit: 'safe', label: 'رمز '.$metaCode));
        WaLog::error('graph.autosuspend', ['meta_code' => $metaCode]);
        // E21: تغيّر فعلي في صحة القناة (E22 تنقله إلى ChannelHealth::transition)
        EventBus::emit(new \App\Events\WhatsApp\ChannelHealthChanged(\App\Events\WhatsApp\ChannelHealthChanged::AVAILABLE,
            \App\Events\WhatsApp\ChannelHealthChanged::SUSPENDED_AUTO, $source, (string) $metaCode));
        WhatsAppInbox::notifyAdmins('⛔ إيقاف إرسال الواتساب', "ردّت ميتا بالرمز {$metaCode} (تقييد الحساب)، فأُوقف كل الإرسال تلقائياً. راجع حالة الحساب قبل إعادة التشغيل.", '/whatsapp/settings');

        return true;
    }
}
