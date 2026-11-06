<?php

namespace App\Services\WhatsApp;


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
 * بعد الانتقال إلى OpenWA (2026-11-06) يبقى الجدول لتصنيف الرموز المحلية ورموز البوابة (OpenWaError) وعرض المحفوظ
 * من رسائل ميتا القديمة. التصنيف الجديد وحده: أُزيل الجدول القديم (LEGACY) وعلم meta_error_v2 والإيقاف الآلي من ميتا.
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
        // الحظر (E31، /block_users): لا إعادة تلقائية لأي منها. النص في سياق الحظر من BLOCK_MESSAGES
        139100 => ['BLOCK_PARTIAL', 'caller', 'message', 'فشل حظر بعض المستخدمين أو فكّه.', 'أعد المحاولة بعد قليل، وإن تكرر فراجع صفحة المحظورين.'],
        139101 => ['BLOCKLIST_FULL', 'caller', 'message', 'بلغت قائمة الحظر حدها عند ميتا (64 ألفاً).', 'فكّ حظر أرقام قديمة من صفحة المحظورين قبل حظر جديد.'],
        139102 => ['BLOCK_FAILED', 'caller', 'message', 'رفضت ميتا طلب الحظر.', 'أبلغ المسؤول إن تكرر، والرمز في سجل التدقيق.'],
        139103 => ['BLOCK_FAILED', 'caller', 'message', 'رفضت ميتا طلب الحظر.', 'أبلغ المسؤول إن تكرر، والرمز في سجل التدقيق.'],
    ];

    /**
     * نص الرفض في نداءات الحظر وفكه (E31): رسالة الإرسال العامة عن النافذة أو رقمنا لا تصف الحظر.
     * ولا «ستُعاد المحاولة» هنا: الحظر لا يُعاد آلياً أبداً.
     */
    public const BLOCK_MESSAGES = [
        131047 => 'لم يراسلنا خلال 24 ساعة أو الرقم غير صالح.',
        131021 => 'لا يمكن حظر رقمنا.',
        139100 => 'فشل حظر بعض المستخدمين.',
        139101 => 'بلغت قائمة الحظر حدها (64 ألفاً).',
        130429 => 'ميتا تحدّ الطلبات الآن. أعد المحاولة بعد دقيقة.',
    ];

    /** رموز ما قبل ميتا والشبكة: رمزنا => [الفئة، النطاق، الرسالة، الإجراء، الإعادة] */
    public const LOCAL = [
        'CHANNEL_NOT_CONFIGURED' => ['auth', 'channel', 'قناة الواتساب غير مهيّأة (رمز الوصول أو معرّف الرقم ناقص).', 'يكمل المدير الإعداد من إعدادات الواتساب.', 'none'],
        'GRAPH_CONFIG_INVALID' => ['auth', 'channel', 'إصدار Graph أو معرّف الرقم غير صالح في الإعدادات.', 'يراجع المدير إعدادات الواتساب.', 'none'],
        'SENDING_SUSPENDED' => ['integrity', 'channel', 'الإرسال موقوف حالياً (قفل طارئ).', 'يرفعه المدير من إعدادات الواتساب بعد مراجعة السبب.', 'none'],
        // E22: حجب ميتا في آلة صحة القناة (wa_health=BLOCKED)، يرفعه المدير يدوياً بعد أن ترفعه ميتا
        'CHANNEL_BLOCKED' => ['integrity', 'channel', 'ميتا تحجب الإرسال من رقم الشركة.', 'يراجع المدير بطاقة «صحة القناة» في إعدادات الواتساب، ويرفع الحجب بعد أن ترفعه ميتا.', 'none'],
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
        // الحظر الرسمي (E31)
        'BLOCKED' => ['caller', 'recipient', 'هذا العميل محظور — فكّ الحظر أولاً من صفحة المحظورين.', 'يفك الحظر من يملك صلاحية الحظر.', 'none'],
        'BLOCK_WINDOW' => ['window', 'recipient', 'لا يمكن الحظر إلا خلال 24 ساعة من آخر رسالة للعميل.', 'إن عاد العميل للمراسلة تُفتح النافذة ويصبح الحظر ممكناً.', 'none'],
        // الرسائل الغنية (E33)
        'FEATURE_OFF' => ['caller', 'message', 'هذه الميزة غير مفعّلة.', 'يفعّلها المدير من إعدادات الواتساب.', 'none'],
        'BAD_REPLY_TARGET' => ['caller', 'message', 'لا يمكن الاقتباس من هذه الرسالة.', 'أرسل الرد بلا اقتباس.', 'none'],
        'BAD_REACTION' => ['caller', 'message', 'هذا التفاعل غير مسموح.', 'اختر من التفاعلات المعروضة.', 'none'],
        'BAD_REACTION_TARGET' => ['caller', 'message', 'لا يمكن التفاعل مع هذه الرسالة.', 'التفاعل على رسائل العميل وحدها.', 'none'],
        'REACTION_TOO_OLD' => ['caller', 'message', 'لا يمكن التفاعل مع رسالة أقدم من 30 يوماً.', 'ردّ بنص بدلاً منه.', 'none'],
        'PLACE_INACTIVE' => ['caller', 'message', 'هذا الموقع غير فعّال.', 'اختر موقعاً من القائمة المعتمدة.', 'none'],
        'CARD_INACTIVE' => ['caller', 'message', 'بطاقة الاتصال غير فعّالة.', 'اختر من البطاقات المعتمدة.', 'none'],
        'BAD_CONTACTS' => ['caller', 'message', 'اختر من بطاقة إلى 3 بطاقات.', 'اختر البطاقات من جديد.', 'none'],
        // السقف الصلب للإرسال الذي يبدؤه النظام (E24)، لا ردود البوت والموظف داخل النافذة
        'DAILY_CAP' => ['caller', 'channel', 'بلغ الإرسال المبادر حدّه اليومي.', 'يرفع المدير السقف من «حدود الصادر» في الإعدادات، أو ينتظر الغد.', 'none'],
        // الرسائل التفاعلية (E36)
        'BAD_INTERACTIVE' => ['caller', 'message', 'رسالة تفاعلية بلا خيارات صالحة.', 'يُرسل النص وحده.', 'none'],
        // بوابة OpenWA (2026-11-06): OpenWa\OpenWaError يصنّف كل رد غير ناجح إلى أحد هذه الرموز
        'NO_CHANNEL' => ['auth', 'channel', 'لا رقم واتساب مربوطاً لهذه المحادثة.', 'يربط المدير رقماً من صفحة «الأرقام».', 'none'],
        'CHANNEL_NOT_READY' => ['transient', 'channel', 'رقم الواتساب غير متصل الآن، ولم تُرسل الرسالة.', 'افتح صفحة «الأرقام» وتأكد أن الهاتف متصل بالإنترنت، أو أعد ربطه.', 'once'],
        'CHANNEL_PAUSED' => ['integrity', 'channel', 'رقم الواتساب موقوف مؤقتاً.', 'يرفع المدير الإيقاف من صفحة «الأرقام» بعد مراجعة السبب.', 'none'],
        'CHANNEL_RESTRICTED' => ['integrity', 'channel', 'واتساب قيّد هذا الرقم (علامة حظر محتمل)، فأُوقف الإرسال منه.', 'راجع الهاتف وتطبيق واتساب عليه قبل رفع الإيقاف.', 'none'],
        'GW_UNREACHABLE' => ['transient', 'channel', 'تعذّر الاتصال ببوابة واتساب، ولم تُرسل الرسالة.', 'أعد المحاولة بعد قليل؛ وإن تكرر فراجع حاوية nuskjo-openwa.', 'once'],
        'GW_TIMEOUT' => ['transient', 'channel', 'انتهت مهلة بوابة واتساب — قد تكون الرسالة وصلت.', 'افتح محادثة العميل وتأكد قبل إعادة الإرسال.', 'none'],
        'GW_AUTH' => ['auth', 'channel', 'البوابة رفضت مفتاح هذا الرقم.', 'يعيد المدير إعداد الرقم من صفحة «الأرقام» («إعادة الإعداد»).', 'none'],
        'GW_SESSION_MISSING' => ['auth', 'channel', 'جلسة هذا الرقم غير موجودة على البوابة.', 'يعيد المدير إعداد الرقم وربطه من صفحة «الأرقام».', 'none'],
        'GW_RATE' => ['throttle', 'channel', 'البوابة تحدّ الطلبات الآن. ستُعاد المحاولة بعد مهلة.', 'انتظر قليلاً.', 'backoff_4x'],
        'GW_REJECTED' => ['caller', 'message', 'رفضت البوابة الرسالة (الصيغة أو الحجم).', 'راجع الرسالة أو الملف وأرسل من جديد.', 'none'],
        'GW_ERROR' => ['unknown', 'message', 'خطأ في بوابة واتساب أثناء الإرسال — قد تكون الرسالة وصلت.', 'افتح محادثة العميل وتأكد قبل إعادة الإرسال؛ وإن تكرر فراجع سجل nuskjo-openwa.', 'none'],
        'RECIPIENT_NOT_ON_WA' => ['quality', 'recipient', 'هذا الرقم ليس على واتساب.', 'تأكد من رقم العميل.', 'none'],
        'QUOTE_NOT_FOUND' => ['caller', 'message', 'الرسالة المقتبسة لم تعد موجودة على الهاتف.', 'أُرسل الرد بلا اقتباس.', 'none'],
        'DELIVERY_FAILED' => ['quality', 'recipient', 'لم تصل الرسالة إلى العميل (رفضها واتساب بعد الإرسال).', 'تأكد من رقم العميل، أو راسله من رقم آخر.', 'none'],
        'MEDIA_EXPIRED' => ['caller', 'message', 'الوسيط لم يعد متاحاً على الهاتف.', 'اطلب من العميل إعادة إرساله.', 'none'],
        'IDEMPOTENCY_IN_PROGRESS' => ['transient', 'message', 'الرسالة نفسها قيد الإرسال الآن.', 'انتظر ثواني حتى تظهر في الخيط.', 'none'],
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

    /** نص رفض ميتا في سياق الحظر وفكه (E31) */
    public static function forBlock(MetaError $err): string
    {
        return self::BLOCK_MESSAGES[$err->metaCode] ?? $err->staffMessage;
    }

    public static function neverRetry(?int $metaCode, ?string $category = null): bool
    {
        return in_array($metaCode, self::NEVER_RETRY, true) || ($category ?? ($metaCode !== null ? self::classify($metaCode)['category'] : null)) === 'auth';
    }

    /**
     * إعادة الإرسال اليدوية من صفحة الإخفاقات (E24): الفشل العابر والخنق من ميتا، وفشل اتصال قبل أن يغادر الطلب.
     * لا رموز الحساب (auth وintegrity) ولا النافذة ولا المستلم (window وquality وcaller وحد الزوج وNEVER_RETRY)،
     * ولا رمز محلي آخر (ملف لم يُرفع أو سياسة حجبت): هذه لا تصلحها الإعادة.
     */
    public static function redrivable(?int $metaCode, ?string $errorCode, ?string $category = null): bool
    {
        if ($metaCode === null) {
            // لم يغادر الطلب أو رفضته البوابة قبل المحرك: الإعادة آمنة
            return in_array($errorCode, ['HTTP_CONNECT_FAILED', 'GW_UNREACHABLE', 'CHANNEL_NOT_READY', 'GW_RATE'], true);
        }
        $entry = self::classify($metaCode);
        $category = $category ?: $entry['category'];

        return in_array($category, ['transient', 'throttle'], true) && !self::neverRetry($metaCode, $category)
            && $entry['code'] !== 'PAIR_RATE_LIMITED';
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

    private static function fromLocal(string $code): array
    {
        [$category, $scope, $message, $action, $retry] = self::LOCAL[$code];

        return ['code' => $code, 'category' => $category, 'scope' => $scope, 'message' => $message, 'action' => $action, 'retry' => $retry];
    }
}
