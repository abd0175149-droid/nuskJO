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

    // ===== النظام =====
    case AuditExport = 'audit.export';
    case SystemReset = 'system.reset';
    case AuditPruned = 'audit.pruned';
    case AppKeyReencrypt = 'system.reencrypt';
    case RetentionPruned = 'retention.pruned';
    case ClientDataExport = 'client.data_export';
    case ConfigExport = 'config.export';

    /** حالة => سبب عدم إصدارها في app/ (تبدأ فارغة) */
    public const INTENTIONALLY_UNEMITTED = [
        // مؤقت أثناء بناء E13 على دفعات؛ يُحذف كل سطر مع الدفعة التي تكتبه
        'ClientDataExport' => 'E13.8 قيد البناء',
        'ConfigExport' => 'E13.8 قيد البناء',
    ];

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
            self::WaErasureVerify, self::WaErasureReject, self::WaDataErase, self::ClientDataExport, self::ConfigExport => AuditSeverity::Warn,
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
            self::WaConversationView, self::WaWebhookBadSignature, self::WaWebhookVerifyFailed => 'noise',
            self::AuthLockout, self::AuthInactiveBlocked, self::AuthzDenied, self::AuthzWouldDeny, self::AuthzUnmapped,
            self::UserCreate, self::UserUpdate, self::UserRoleChange, self::UserPasswordReset, self::UserPasswordChange,
            self::UserActivate, self::UserDeactivate, self::UserDelete, self::UserRestoreAdmin,
            self::RoleCreate, self::RoleUpdate, self::RoleDelete, self::WaLinkClient, self::WaUnlinkClient => 'access',
            self::SettingsUpdate, self::SettingsDroppedKeys, self::BackupRestoreAck, self::OfferBotVisibility => 'config',
            self::QuoteRequestCreate, self::WaHandoff => 'agent',
            self::WaAccountUpdate, self::WaAccountAlert, self::WaSecurity, self::WaQualityUpdate, self::WaReviewUpdate,
            self::WaCapabilityUpdate, self::WaWebhookOther, self::WaSuspendAuto, self::WaIdentityChange => 'meta',
            self::AuditExport, self::SystemReset, self::AuditPruned, self::AppKeyReencrypt, self::WaBackfillIdentity,
            self::RetentionPruned, self::ConfigExport => 'system',
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
            self::AuditExport => 'تصدير سجل التدقيق',
            self::SystemReset => 'مسح بيانات النظام',
            self::AuditPruned => 'تقليم سجل التدقيق',
            self::AppKeyReencrypt => 'إعادة تشفير الأسرار',
        };
    }

    /** يُكتب ولو كان audit.extended مطفأً: المال، وقفل الدخول (يُدقَّق منذ E03)، والإيقاف التلقائي للإرسال */
    public function alwaysOn(): bool
    {
        return $this->category() === 'money' || in_array($this, [self::AuthLockout, self::WaSuspendAuto], true);
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
