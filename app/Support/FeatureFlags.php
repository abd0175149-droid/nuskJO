<?php

namespace App\Support;

use App\Models\WaBotSetting;
use Illuminate\Support\Facades\Log;

/**
 * أعلام الميزات المركزية (E10): كل ميزة لاحقة تُشحن هنا مطفأة، وتُفعَّل بلا نشر.
 * الأولوية: البيئة (config/features.php) ثم المحفوظ (wa_bot_settings.feature_flags، يبدّله المدير بسبب ويُدقَّق)
 * ثم الافتراض (false دائماً). علم available=false لم يُشحن كوده بعد: لا يعمل ولا يُبدَّل حتى تشحنه ملحمته.
 */
final class FeatureFlags
{
    public const DEFINITIONS = [
        'llm_circuit_breaker' => ['label' => 'قاطع دائرة مزوّد الذكاء', 'capability' => 'C256', 'available' => false],
        'inbound_event_log' => ['label' => 'حفظ إشعارات واتساب مشفّرة قبل الإقرار وإعادة معالجة الفاشل (72 ساعة حداً أقصى)', 'capability' => 'C261', 'available' => true],
        'webhook_fence_enforce' => ['label' => 'رفض إشعارات حساب أو رقم غريب فعلاً (مطفأ = تسجيل فقط)', 'capability' => 'C165', 'available' => true],
        'retention_purge' => ['label' => 'حذف دوري بحسب مدة الاحتفاظ', 'capability' => 'C287', 'available' => true],
        'media_archive' => ['label' => 'أرشفة مرفقات العملاء (صور ووثائق)', 'capability' => 'C283', 'available' => true],
        'media_consent_prompt' => ['label' => 'إشعار العميل بحفظ ملفاته مع زر «احذف ملفاتي»', 'capability' => 'C283', 'available' => true],
        'tool_validation_enforce' => ['label' => 'رفض مدخلات الأدوات غير الصالحة (بدل التسجيل والقصّ)', 'capability' => 'C272', 'available' => true],
        'inbox_v2' => ['label' => 'صندوق المحادثات الجديد (الجوال والتنسيق وتحميل المزيد)', 'capability' => 'C130', 'available' => true],
        'admin_readonly' => ['label' => 'المساعد الإداري للقراءة فقط (يوقف كل تعديل من أرقام الإدارة)', 'capability' => 'C105', 'available' => true],
        'outbound_policy_enforce' => ['label' => 'حجب الرسائل الآلية التي تحوي بيانات حساسة أو داخلية (بدل التسجيل)', 'capability' => 'C175', 'available' => true],
        'admin_rbac_enforce' => ['label' => 'فرض صلاحيات المستخدم المرتبط على أرقام الإدارة (بدل التسجيل)', 'capability' => 'C172', 'available' => true],
        'inbox_followup' => ['label' => 'متابعة الصندوق: الإغلاق والتأجيل والتثبيت و«غير مقروء» والبطاقات', 'capability' => 'C126', 'available' => true],
        'quote_dedupe' => ['label' => 'منع تكرار طلبات التسعير والحجز (المكرر يعيد رقم الطلب نفسه بلا إشعار ثانٍ)', 'capability' => 'C122', 'available' => true],
        'reprocess_button' => ['label' => 'زر «إعادة معالجة» للردود العالقة (للمدير)', 'capability' => 'C060', 'available' => true],
        'meta_error_v2' => ['label' => 'تصنيف أخطاء ميتا الجديد: سبب عربي وإجراء وتنبيه موحّد (مطفأ = وضع ظل يكتب التصنيف فقط)', 'capability' => 'C080', 'available' => true],
        'send_ledger' => ['label' => 'دفتر الإرسال: حفظ الرسالة قبل إرسالها ومنع التكرار وحسم «غير مؤكد» والمنظّف', 'capability' => 'C250', 'available' => true],
        'send_retry' => ['label' => 'إعادة محدودة لرموز ميتا العابرة وفشل الاتصال (بعد أسبوع من دفتر الإرسال)', 'capability' => 'C279', 'available' => true],
        'quick_replies' => ['label' => 'الردود الجاهزة في المنشئ (ملء المتغيرات في الخادم)', 'capability' => 'C066', 'available' => true],
        // E27: العامل الثاني للموظفين. admin_only: لا يبدّله من الإعدادات إلا المدير (إطفاؤه يُضعف حماية الجميع)
        'staff_mfa' => ['label' => 'التحقق بخطوتين للموظفين: صفحة «الأمان» للتسجيل بتطبيق مصادقة (من سجّل يُتحدّى عند الدخول دائماً)', 'capability' => 'C194', 'available' => true, 'admin_only' => true],
        'step_up' => ['label' => 'تأكيد الهوية قبل الأفعال الحساسة (اعتماد، إعدادات، أسرار، مستخدمون…) مرة كل ربع ساعة', 'capability' => 'C194', 'available' => true, 'admin_only' => true],
        'admin_number_mfa' => ['label' => 'أرقام الإدارة التي تعدّل: حساب مرتبط إلزامي، والمساعد موقوف حتى يفعّل صاحبه التحقق بخطوتين', 'capability' => 'C194', 'available' => true, 'admin_only' => true],
        'idle_report' => ['label' => 'تقرير أسبوعي للمدراء بالحسابات وأرقام الإدارة الخاملة (تنبيه بلا تعطيل)', 'capability' => 'C162', 'available' => true],
    ];

    /** @var array<string, array>|null بديل DEFINITIONS في الاختبارات وحدها (fake) */
    private static ?array $fake = null;

    /** للاختبارات: علم متاح لا يوجد بعد في الكود. null يعيد التعريفات الحقيقية */
    public static function fake(?array $definitions): void
    {
        self::$fake = $definitions;
    }

    /** @return array<string, array{label: string, capability: string, available: bool, admin_only?: bool}> */
    public static function definitions(): array
    {
        return self::$fake ?? self::DEFINITIONS;
    }

    public static function on(string $key): bool
    {
        if (!isset(self::definitions()[$key])) {
            if (app()->runningUnitTests()) {
                throw new \InvalidArgumentException("علم غير معرّف: {$key}");
            }
            Log::error('feature_flag.unknown', ['flag' => $key]);

            return false;
        }

        return self::available($key) && self::state($key)['on'];
    }

    public static function available(string $key): bool
    {
        return (bool) (self::definitions()[$key]['available'] ?? false);
    }

    /** @return array{on: bool, source: string} source: env | db | default */
    public static function state(string $key): array
    {
        $env = config("features.env.{$key}");
        if ($env !== null && $env !== '') {
            return ['on' => (bool) filter_var($env, FILTER_VALIDATE_BOOL), 'source' => 'env'];
        }
        $db = (WaBotSetting::current()->feature_flags ?? [])[$key] ?? null;
        if ($db !== null) {
            return ['on' => (bool) $db, 'source' => 'db'];
        }

        return ['on' => false, 'source' => 'default'];
    }

    /** للوحة الأعلام: بلا قيم سرية */
    public static function panel(): array
    {
        $out = [];
        foreach (self::definitions() as $key => $d) {
            $state = self::state($key);
            $out[] = ['key' => $key, 'label' => $d['label'], 'available' => self::available($key)] + $state;
        }

        return $out;
    }
}
