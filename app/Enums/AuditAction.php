<?php

namespace App\Enums;

/**
 * أفعال سجل التدقيق. القيمة هي النص المخزّن في audit_logs.action (30 حرفاً على الأكثر)، والقيم القديمة باقية
 * كما هي (create وapprove... وuser.* وrole.* وauthz.* من E03) فلا تحتاج البيانات القديمة ترحيلاً.
 * كل حالة تُصدَر في app/ أو تُذكر في INTENTIONALLY_UNEMITTED بسبب (AuditCoverageTest).
 */
enum AuditAction: string
{
    // ===== المال والسجلات =====
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Approve = 'approve';
    case Reject = 'reject';
    case StartEdit = 'start_edit';
    case Settle = 'settle';
    case Reverse = 'reverse';
    case PeriodClose = 'period.close';
    case PeriodOpen = 'period.open';
    case YearClose = 'year.close';
    case LineRelocate = 'journal.relocate_line';

    // ===== الدخول والوصول =====
    case AuthLogin = 'auth.login';
    case AuthLogout = 'auth.logout';
    case AuthLoginFailed = 'auth.login_failed';
    case AuthLockout = 'auth.lockout';
    case AuthInactiveBlocked = 'auth.inactive_blocked';
    case AccessDenied = 'access.denied';
    case AuthzDenied = 'authz.denied';
    case AuthzWouldDeny = 'authz.would_deny';
    case AuthzUnmapped = 'authz.unmapped';
    // العامل الثاني والتحقق المعزّز (E27): لا سر ولا رمز في changes أبداً
    case AuthMfaEnrolled = 'auth.mfa_enrolled';
    case AuthMfaDisabled = 'auth.mfa_disabled';
    case AuthMfaPassed = 'auth.mfa_passed';
    case AuthMfaFailed = 'auth.mfa_failed';
    case AuthMfaRecoveryUsed = 'auth.mfa_recovery_used';
    case AuthMfaCodesRegenerated = 'auth.mfa_codes_regenerated';
    case AuthMfaReset = 'auth.mfa_reset';
    case AuthMfaLockout = 'auth.mfa_lockout';
    case AuthMfaBypassed = 'auth.mfa_bypassed';
    case AuthStepUp = 'auth.step_up';
    case AuthStepUpFailed = 'auth.step_up_failed';

    // ===== المستخدمون والأدوار والإعدادات =====
    case UserCreate = 'user.create';
    case UserUpdate = 'user.update';
    case UserRoleChange = 'user.role_change';
    case UserPasswordReset = 'user.password_reset';
    case UserPasswordChange = 'user.password_change';
    case UserActivate = 'user.activate';
    case UserDeactivate = 'user.deactivate';
    case UserDelete = 'user.delete';
    case UserRestoreAdmin = 'user.restore_admin';
    case RoleCreate = 'role.create';
    case RoleUpdate = 'role.update';
    case RoleDelete = 'role.delete';
    case SettingsUpdate = 'settings.update';
    case SettingsDroppedKeys = 'settings.dropped_keys';
    case ExchangeRateSet = 'settings.exchange_rate';
    case BackupRestoreAck = 'backup.restore_ack';

    // ===== العروض والتسعير =====
    case OfferPriceChange = 'offer.price_change';
    case OfferBotVisibility = 'offer.bot_visibility';
    case QuoteRequestCreate = 'quote.create';
    case QuotePrice = 'quote.price';
    case QuoteStatus = 'quote.status';

    // ===== واتساب من جهة الموظفين والنظام =====
    case WaSettingsUpdate = 'wa.settings_update';
    case WaBotEnabled = 'wa.bot_enabled';
    case WaSuspendManual = 'wa.suspend_manual';
    case WaSuspendAuto = 'wa.suspend_auto';
    case WaBotToggle = 'wa.bot_toggle';
    case WaAssign = 'wa.assign';
    case WaTopic = 'wa.topic';
    case WaRoutingUpdate = 'wa.routing_update';
    case WaModelPrices = 'wa.model_prices';
    case WaToolsToggle = 'wa.tools_toggle';
    case WaConversationView = 'wa.conversation_view';
    case WaHandoff = 'wa.handoff';
    case WaReliabilityFlag = 'wa.reliability_flag';
    case WaIdentityMode = 'wa.identity_mode';
    case WaSecretSet = 'wa.secret_set';
    case WaSecretClear = 'wa.secret_clear';
    case WaVerifyRotate = 'wa.verify_rotate';
    case WaFlagToggle = 'wa.flag_toggle';
    case WaSettingsFinalize = 'wa.settings_finalize';
    case WaMerge = 'wa.merge';
    case WaIdentityChange = 'wa.identity_change';
    case WaLinkClient = 'wa.link_client';
    case WaUnlinkClient = 'wa.unlink_client';
    case WaBackfillIdentity = 'wa.backfill_identity';
    case WaBreakerManual = 'wa.breaker_manual';
    case WaErasureRequest = 'wa.erasure_request';
    case WaErasureVerify = 'wa.erasure_verify';
    case WaErasureReject = 'wa.erasure_reject';
    case WaDataErase = 'wa.data_erase';
    case WaMediaView = 'wa.media_view';
    case WaMediaDownload = 'wa.media_download';
    case WaMediaRefetch = 'wa.media_refetch';
    case WaMediaPurge = 'wa.media_purge';
    case WaMediaConsent = 'wa.media_consent';
    case WaPolicyOverride = 'wa.policy_override';
    case WaConvClose = 'wa.conv_close';
    case WaConvReopen = 'wa.conv_reopen';
    case WaConvSnooze = 'wa.conv_snooze';
    case WaReprocess = 'wa.reprocess';
    case WaQuickReplyCreate = 'wa.quick_reply_create';
    case WaQuickReplyUpdate = 'wa.quick_reply_update';
    case WaQuickReplyDelete = 'wa.quick_reply_delete';
    case WaQuickReplyApprove = 'wa.quick_reply_approve';
    case WaLastErrorCleared = 'wa.last_error_clear';
    // E29: التصنيفات والتثبيت داخل الخيط والترجمة عند الطلب
    case WaLabelCreate = 'wa.label_create';
    case WaLabelUpdate = 'wa.label_update';
    case WaLabelDelete = 'wa.label_delete';
    case WaLabelAttach = 'wa.label_attach';
    case WaLabelDetach = 'wa.label_detach';
    case WaPinSet = 'wa.pin_set';
    case WaPinClear = 'wa.pin_clear';
    case WaTranslate = 'wa.translate';
    case WaLlmTermsConfirm = 'wa.llm_terms_confirm';
    // E30: البحث في نص الرسائل (بالعيّنات، بلا نص الاستعلام)، وتصدير محادثة، وإصلاح فهرس البحث
    case WaMessageSearch = 'wa.message_search';
    case WaConversationExport = 'wa.conv_export';
    case WaSearchIndexRepair = 'wa.search_index_repair';
    // E37: قواعد الرد الحتمية والمكوّنات الرسمية في واتساب
    case WaAutoRuleCreate = 'wa.auto_rule_create';
    case WaAutoRuleUpdate = 'wa.auto_rule_update';
    case WaAutoRuleDelete = 'wa.auto_rule_delete';
    case WaAutoRuleToggle = 'wa.auto_rule_toggle';
    case WaAutoRuleReorder = 'wa.auto_rule_reorder';
    case WaConvAutomationSync = 'wa.conv_automation_sync';
    // الملفات الصادرة (E32): كل ملف خرج لعميل (بلا اسمه)، والفاتورة على واتساب في سجل الفاتورة، والحذف من خوادم ميتا
    case WaFileSent = 'wa.file_sent';
    case WaInvoiceSent = 'wa.invoice_sent';
    case WaMediaRemoteDelete = 'wa.media_remote_delete';
    case WaAdminMfaBlocked = 'wa.admin_mfa_blocked';

    // ===== ميتا =====
    case WaAccountUpdate = 'wa.account_update';
    case WaAccountAlert = 'wa.account_alert';
    case WaSecurity = 'wa.security';
    case WaQualityUpdate = 'wa.quality_update';
    case WaReviewUpdate = 'wa.review_update';
    case WaCapabilityUpdate = 'wa.capability_update';
    case WaWebhookOther = 'wa.webhook_other';
    case WaWebhookBadSignature = 'wa.webhook_bad_sig';
    case WaWebhookVerifyFailed = 'wa.webhook_verify_fail';
    case WaWebhookReplay = 'wa.webhook_replay';
    // E21: تفضيلات واتساب (user_preferences) وتغيّر صحة القناة من الناقل
    case WaOptoutMeta = 'wa.optout_meta';
    case WaOptinMeta = 'wa.optin_meta';
    case WaHealthChange = 'wa.health_change';

    // ===== النظام =====
    case AuditExport = 'audit.export';
    case SystemReset = 'system.reset';
    case AuditPruned = 'audit.pruned';
    case AppKeyReencrypt = 'system.reencrypt';
    case RetentionPruned = 'retention.pruned';
    case ClientDataExport = 'client.data_export';
    case ConfigExport = 'config.export';

    /** حالة => سبب عدم إصدارها في app/ (تبدأ فارغة) */
    public const INTENTIONALLY_UNEMITTED = [];

    public function severity(): AuditSeverity
    {
        return match ($this) {
            self::WaSuspendAuto, self::SystemReset => AuditSeverity::Error,
            self::Delete, self::Reject, self::StartEdit, self::Settle, self::Reverse,
            self::PeriodOpen, self::YearClose, self::LineRelocate,
            self::AuthLockout, self::AuthInactiveBlocked, self::AuthzDenied,
            self::UserCreate, self::UserRoleChange, self::UserPasswordReset, self::UserActivate, self::UserDeactivate,
            self::UserDelete, self::UserRestoreAdmin, self::RoleCreate, self::RoleUpdate, self::RoleDelete,
            self::SettingsDroppedKeys, self::ExchangeRateSet, self::BackupRestoreAck,
            self::OfferPriceChange, self::OfferBotVisibility, self::QuotePrice,
            self::WaSettingsUpdate, self::WaBotEnabled, self::WaSuspendManual, self::WaRoutingUpdate,
            self::WaReliabilityFlag, self::WaIdentityMode, self::WaSecurity, self::AuditExport,
            self::WaSecretSet, self::WaSecretClear, self::WaVerifyRotate, self::WaFlagToggle,
            self::WaSettingsFinalize, self::AppKeyReencrypt, self::WaMerge, self::WaLinkClient, self::WaUnlinkClient, self::WaBackfillIdentity, self::WaBreakerManual,
            self::WaErasureVerify, self::WaErasureReject, self::WaDataErase, self::ClientDataExport, self::ConfigExport,
            self::WaMediaDownload, self::WaPolicyOverride, self::WaReprocess, self::WaQuickReplyApprove, self::WaWebhookReplay,
            self::WaHealthChange => AuditSeverity::Warn,
            self::WaLlmTermsConfirm => AuditSeverity::Warn,
            self::WaConversationExport, self::WaSearchIndexRepair => AuditSeverity::Warn,
            self::AuthMfaDisabled, self::AuthMfaRecoveryUsed, self::AuthMfaReset, self::AuthMfaLockout, self::AuthMfaBypassed,
            self::WaAdminMfaBlocked => AuditSeverity::Warn,
            default => AuditSeverity::Info,
        };
    }

    /** money · access · config · whatsapp · agent · meta · noise · system */
    public function category(): string
    {
        return match ($this) {
            self::Create, self::Update, self::Delete, self::Approve, self::Reject, self::StartEdit, self::Settle,
            self::Reverse, self::PeriodClose, self::PeriodOpen, self::YearClose, self::LineRelocate,
            self::OfferPriceChange, self::QuotePrice, self::ExchangeRateSet => 'money',
            self::AuthLogin, self::AuthLogout, self::AuthLoginFailed, self::AccessDenied,
            self::WaConversationView, self::WaWebhookBadSignature, self::WaWebhookVerifyFailed, self::WaTranslate,
            self::AuthMfaPassed, self::AuthMfaFailed, self::AuthStepUp, self::AuthStepUpFailed => 'noise',
            self::AuthLockout, self::AuthInactiveBlocked, self::AuthzDenied, self::AuthzWouldDeny, self::AuthzUnmapped,
            self::UserCreate, self::UserUpdate, self::UserRoleChange, self::UserPasswordReset, self::UserPasswordChange,
            self::UserActivate, self::UserDeactivate, self::UserDelete, self::UserRestoreAdmin,
            self::RoleCreate, self::RoleUpdate, self::RoleDelete, self::WaLinkClient, self::WaUnlinkClient,
            self::AuthMfaEnrolled, self::AuthMfaDisabled, self::AuthMfaRecoveryUsed, self::AuthMfaCodesRegenerated,
            self::AuthMfaReset, self::AuthMfaLockout, self::AuthMfaBypassed => 'access',
            self::SettingsUpdate, self::SettingsDroppedKeys, self::BackupRestoreAck, self::OfferBotVisibility,
            self::WaQuickReplyCreate, self::WaQuickReplyUpdate, self::WaQuickReplyDelete, self::WaQuickReplyApprove,
            self::WaLabelCreate, self::WaLabelUpdate, self::WaLabelDelete, self::WaLlmTermsConfirm => 'config',
            self::WaAutoRuleCreate, self::WaAutoRuleUpdate, self::WaAutoRuleDelete, self::WaAutoRuleToggle, self::WaAutoRuleReorder,
            self::WaConvAutomationSync => 'config',
            self::QuoteRequestCreate, self::WaHandoff => 'agent',
            self::WaAccountUpdate, self::WaAccountAlert, self::WaSecurity, self::WaQualityUpdate, self::WaReviewUpdate,
            self::WaCapabilityUpdate, self::WaWebhookOther, self::WaSuspendAuto, self::WaIdentityChange, self::WaLastErrorCleared,
            self::WaOptoutMeta, self::WaOptinMeta, self::WaHealthChange => 'meta',
            self::AuditExport, self::SystemReset, self::AuditPruned, self::AppKeyReencrypt, self::WaBackfillIdentity,
            self::RetentionPruned, self::ConfigExport, self::WaWebhookReplay, self::WaSearchIndexRepair, self::WaMediaRemoteDelete => 'system',
            default => 'whatsapp',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Create => 'إنشاء',
            self::Update => 'تعديل',
            self::Delete => 'حذف',
            self::Approve => 'اعتماد',
            self::Reject => 'رفض',
            self::StartEdit => 'بدء تعديل معتمد',
            self::Settle => 'تسوية',
            self::Reverse => 'عكس قيد',
            self::PeriodClose => 'إقفال فترة',
            self::PeriodOpen => 'فتح فترة',
            self::YearClose => 'إقفال سنة',
            self::LineRelocate => 'نقل سطر قيد',
            self::AuthLogin => 'دخول',
            self::AuthLogout => 'خروج',
            self::AuthLoginFailed => 'محاولة دخول فاشلة',
            self::AuthLockout => 'قفل الدخول (محاولات كثيرة)',
            self::AuthInactiveBlocked => 'دخول حساب معطّل',
            self::AccessDenied => 'رفض وصول',
            self::AuthzDenied => 'رفض صلاحية',
            self::AuthzWouldDeny => 'رفض صلاحية (تسجيل فقط)',
            self::AuthzUnmapped => 'مسار بلا صلاحية',
            self::AuthMfaEnrolled => 'تفعيل التحقق بخطوتين',
            self::AuthMfaDisabled => 'تعطيل التحقق بخطوتين',
            self::AuthMfaPassed => 'اجتياز التحقق بخطوتين',
            self::AuthMfaFailed => 'رمز تحقق خاطئ',
            self::AuthMfaRecoveryUsed => 'استعمال رمز استرداد',
            self::AuthMfaCodesRegenerated => 'تجديد رموز الاسترداد',
            self::AuthMfaReset => 'إعادة تعيين التحقق بخطوتين',
            self::AuthMfaLockout => 'قفل التحقق بخطوتين (محاولات كثيرة)',
            self::AuthMfaBypassed => 'دخول بلا تحدٍّ (منفذ الطوارئ مفعّل)',
            self::AuthStepUp => 'تأكيد الهوية قبل فعل حساس',
            self::AuthStepUpFailed => 'فشل تأكيد الهوية',
            self::UserCreate => 'إنشاء مستخدم',
            self::UserUpdate => 'تعديل مستخدم',
            self::UserRoleChange => 'تغيير دور مستخدم',
            self::UserPasswordReset => 'إعادة تعيين كلمة مرور',
            self::UserPasswordChange => 'تغيير كلمة المرور',
            self::UserActivate => 'تفعيل مستخدم',
            self::UserDeactivate => 'تعطيل مستخدم',
            self::UserDelete => 'حذف مستخدم',
            self::UserRestoreAdmin => 'استعادة مدير (طرفية)',
            self::RoleCreate => 'إنشاء دور',
            self::RoleUpdate => 'تعديل دور',
            self::RoleDelete => 'حذف دور',
            self::SettingsUpdate => 'تعديل الإعدادات',
            self::SettingsDroppedKeys => 'مفاتيح إعدادات مرفوضة',
            self::ExchangeRateSet => 'سعر صرف',
            self::BackupRestoreAck => 'اعتماد مراجعة الاستعادة',
            self::OfferPriceChange => 'تغيير سعر عرض',
            self::OfferBotVisibility => 'إظهار عرض للبوت',
            self::QuoteRequestCreate => 'طلب تسعير',
            self::QuotePrice => 'تسعير طلب',
            self::QuoteStatus => 'حالة طلب تسعير',
            self::WaSettingsUpdate => 'إعدادات الواتساب',
            self::WaBotEnabled => 'تشغيل البوت أو إيقافه',
            self::WaSuspendManual => 'قفل الإرسال الطارئ',
            self::WaSuspendAuto => 'قفل إرسال تلقائي',
            self::WaBotToggle => 'البوت في محادثة',
            self::WaAssign => 'توكيل محادثة',
            self::WaTopic => 'وسم موضوع',
            self::WaRoutingUpdate => 'توجيه المواضيع',
            self::WaModelPrices => 'أسعار النموذج',
            self::WaToolsToggle => 'أدوات البوت',
            self::WaConversationView => 'فتح محادثة',
            self::WaHandoff => 'تحويل لموظف',
            self::WaReliabilityFlag => 'علم موثوقية الرد',
            self::WaIdentityMode => 'وضع العميل بلا رقم',
            self::WaSecretSet => 'تعيين سر القناة',
            self::WaSecretClear => 'مسح سر القناة',
            self::WaVerifyRotate => 'توليد رمز تحقق الويبهوك',
            self::WaFlagToggle => 'تبديل علم ميزة',
            self::WaSettingsFinalize => 'إقفال ترقية الإعدادات',
            self::WaMerge => 'دمج محادثتين لعميل واحد',
            self::WaIdentityChange => 'تغيّر رقم العميل أو معرّفه',
            self::WaLinkClient => 'ربط محادثة بعميل',
            self::WaUnlinkClient => 'فك ربط محادثة بعميل',
            self::WaBackfillIdentity => 'تعبئة هوية المحادثات القديمة',
            self::WaBreakerManual => 'فتح أو إغلاق وضع التحويل يدوياً',
            self::WaErasureRequest => 'طلب حذف بيانات',
            self::WaErasureVerify => 'تحقّق من هوية طالب الحذف',
            self::WaErasureReject => 'رفض طلب حذف',
            self::WaDataErase => 'حذف بيانات عميل',
            self::WaMediaView => 'عرض مرفق عميل',
            self::WaMediaDownload => 'تنزيل مرفق عميل',
            self::WaMediaRefetch => 'تنزيل يدوي لمرفق',
            self::WaMediaPurge => 'حذف مرفقات عميل',
            self::WaMediaConsent => 'اختيار العميل حفظ ملفاته',
            self::WaPolicyOverride => 'إرسال رغم تحذير بيانات حساسة',
            self::WaConvClose => 'إغلاق محادثة',
            self::WaConvReopen => 'إعادة فتح محادثة',
            self::WaConvSnooze => 'تأجيل محادثة',
            self::WaReprocess => 'إعادة معالجة رد عالق',
            self::WaQuickReplyCreate => 'إضافة رد جاهز',
            self::WaQuickReplyUpdate => 'تعديل رد جاهز',
            self::WaQuickReplyDelete => 'حذف رد جاهز',
            self::WaQuickReplyApprove => 'اعتماد رد جاهز يذكر بيانات دفع',
            self::WaLastErrorCleared => 'تصفير آخر خطأ من ميتا («تمّ الحل»)',
            self::WaLabelCreate => 'إضافة تصنيف محادثات',
            self::WaLabelUpdate => 'تعديل تصنيف محادثات',
            self::WaLabelDelete => 'حذف تصنيف محادثات',
            self::WaLabelAttach => 'وضع تصنيف على محادثة',
            self::WaLabelDetach => 'إزالة تصنيف عن محادثة',
            self::WaPinSet => 'تثبيت ملاحظة أو رسالة داخلية',
            self::WaPinClear => 'إلغاء تثبيت داخلي',
            self::WaTranslate => 'ترجمة رسالة للموظف',
            self::WaLlmTermsConfirm => 'تأكيد الطبقة المدفوعة لمزوّد الذكاء',
            self::WaMessageSearch => 'بحث في نص الرسائل',
            self::WaConversationExport => 'تصدير محادثة واتساب',
            self::WaSearchIndexRepair => 'إصلاح فهرس البحث في الرسائل',
            self::WaAutoRuleCreate => 'إضافة قاعدة رد حتمية',
            self::WaAutoRuleUpdate => 'تعديل قاعدة رد حتمية',
            self::WaAutoRuleDelete => 'حذف قاعدة رد حتمية',
            self::WaAutoRuleToggle => 'تفعيل قاعدة رد حتمية أو إيقافها',
            self::WaAutoRuleReorder => 'ترتيب قواعد الرد الحتمية',
            self::WaConvAutomationSync => 'مزامنة الأوامر والأسئلة المقترحة مع واتساب',
            self::WaFileSent => 'إرسال ملف لعميل على واتساب',
            self::WaInvoiceSent => 'إرسال فاتورة PDF على واتساب',
            self::WaMediaRemoteDelete => 'حذف ملفات مرسلة من خوادم واتساب',
            self::WaAdminMfaBlocked => 'المساعد الإداري موقوف لرقم مستخدمه بلا تحقق بخطوتين',
            self::RetentionPruned => 'تقليم بحسب مدة الاحتفاظ',
            self::ClientDataExport => 'تصدير بيانات عميل',
            self::ConfigExport => 'تصدير إعدادات البوت',
            self::WaAccountUpdate => 'حدث حساب ميتا',
            self::WaAccountAlert => 'تنبيه حساب ميتا',
            self::WaSecurity => 'حدث أمان ميتا',
            self::WaQualityUpdate => 'جودة الرقم',
            self::WaReviewUpdate => 'مراجعة الحساب',
            self::WaCapabilityUpdate => 'قدرات الحساب',
            self::WaWebhookOther => 'حدث ميتا آخر',
            self::WaWebhookBadSignature => 'توقيع ويبهوك مرفوض',
            self::WaWebhookVerifyFailed => 'فشل ربط الويبهوك',
            self::WaWebhookReplay => 'إعادة معالجة إشعار ويبهوك',
            self::WaOptoutMeta => 'إيقاف التسويق من تفضيلات واتساب',
            self::WaOptinMeta => 'استئناف التسويق من تفضيلات واتساب',
            self::WaHealthChange => 'تغيّر حالة قناة الإرسال',
            self::AuditExport => 'تصدير سجل التدقيق',
            self::SystemReset => 'مسح بيانات النظام',
            self::AuditPruned => 'تقليم سجل التدقيق',
            self::AppKeyReencrypt => 'إعادة تشفير الأسرار',
        };
    }

    /** يُكتب ولو كان audit.extended مطفأً: المال، وقفل الدخول (يُدقَّق منذ E03)، والإيقاف التلقائي للإرسال، والاطّلاع على مرفقات العملاء (E14) */
    public function alwaysOn(): bool
    {
        return $this->category() === 'money' || in_array($this, [self::AuthLockout, self::WaSuspendAuto, self::WaMediaView, self::WaMediaDownload, self::WaMediaRefetch,
            // إعادة إشعار بعينه بيد مطوّر (نادرة، وقد تتجاوز الميزانية): أثرها دائم
            self::WaWebhookReplay,
            // E30: البحث عبر المحادثات اطّلاع على بيانات العملاء (صف بالعيّنات لكل موظف)، وإصلاح الفهرس نادر
            self::WaMessageSearch, self::WaSearchIndexRepair,
            // كل ملف خرج لعميل (قد يحمل رقم جواز) وكل فاتورة على واتساب (E32)
            self::WaFileSent, self::WaInvoiceSent,
            // E27: قفل التحدّي، والدخول بمنفذ الطوارئ، وإيقاف المساعد لرقم بلا عامل ثانٍ (مرة في اليوم)
            self::AuthMfaLockout, self::AuthMfaBypassed, self::WaAdminMfaBlocked], true);
    }

    public function isNoise(): bool
    {
        return $this->category() === 'noise';
    }

    /** @return list<string> */
    public static function noiseValues(): array
    {
        return array_values(array_map(fn (self $a) => $a->value, array_filter(self::cases(), fn (self $a) => $a->isNoise())));
    }
}
