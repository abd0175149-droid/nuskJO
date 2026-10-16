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
        // E21: حالات التسليم والناقل الداخلي وتفضيلات واتساب
        'delivery_status_v2' => ['label' => 'حالات التسليم الذرية: لا تتراجع، بوقت ميتا وتاريخ بلا تكرار، والإيصال المبكر لا يضيع، وعلامات ✓ و✓✓ في الصندوق', 'capability' => 'C050', 'available' => true],
        'wa_event_bus' => ['label' => 'ناقل الأحداث الداخلي: إشعار التحويل و«لم تصل رسالتك» وتدقيقهما من المستمعين بدل الإشعار المباشر', 'capability' => 'C087', 'available' => true],
        'user_preferences_optout' => ['label' => 'تسجيل إيقاف العميل للتسويق من إعدادات واتساب (user_preferences) في قائمة الرافضين', 'capability' => 'C087', 'available' => true],
        // E29: التصنيفات وشارات النظام، والملاحظة المثبتة وبطاقة الحقائق، والترجمة عند الطلب (بعد تأكيد الطبقة المدفوعة)
        'wa_labels' => ['label' => 'تصنيفات سير العمل على المحادثات وشارات من النظام (داخلية، لا تغيّر من يرى المحادثة)', 'capability' => 'C127', 'available' => true],
        'thread_pins' => ['label' => 'الملاحظة المثبتة الداخلية والرسائل المثبتة وبطاقة حقائق العميل', 'capability' => 'C128', 'available' => true],
        'staff_translation' => ['label' => 'ترجمة رسائل العملاء للموظف عند الطلب، ومعاينة ترجمة الرد مع فحص الأرقام', 'capability' => 'C293', 'available' => true],
        // E30: البحث للمدراء أولاً (من يرى كل المحادثات)، ثم للجميع بالعلم الثاني؛ والحصر بالسياج في الحالتين
        'message_search' => ['label' => 'البحث في نص الرسائل (للمدراء: من يرى كل المحادثات)', 'capability' => 'C141', 'available' => true],
        'message_search_all' => ['label' => 'البحث في الرسائل لكل موظفي الصندوق، كلٌّ ضمن محادثاته (بعد أسبوع من الأول)', 'capability' => 'C141', 'available' => true],
        'conversation_export' => ['label' => 'تصدير محادثة واحدة (نص أو CSV) بسبب مكتوب، لمن يملك صلاحية التصدير', 'capability' => 'C140', 'available' => true],
        // E26: فوق CSP_MODE=report وحده؛ CSP_MODE=off يطفئ السياسة كلها وenforce يفرضها من البيئة
        'csp_enforce' => ['label' => 'فرض سياسة أمن المحتوى في المتصفح (مطفأ = تقارير فقط بلا حجب)', 'capability' => 'C184', 'available' => true],
        // E35: الوضع من الإعداد send_read_receipts؛ مطفأ = لا إشعار قراءة ولا «يكتب…» مهما كان الوضع
        'read_receipts' => ['label' => 'إشعار القراءة ومؤشر «يكتب…» (الوضع من «ربط قناة ميتا»؛ مطفأ = لا شيء يصل ميتا)', 'capability' => 'C049', 'available' => true],
        // E37: الوضع shadow أو live من صفحة الردود الحتمية؛ مطفأ = النموذج يرد على كل شيء كما كان
        'auto_rules' => ['label' => 'الردود الحتمية: نص ثابت معتمد لأسئلة متكررة قبل النموذج (الظل أولاً)', 'capability' => 'C117', 'available' => true],
        'conversational_components' => ['label' => 'مزامنة الأوامر والأسئلة المقترحة (ice breakers) مع واتساب', 'capability' => 'C117', 'available' => true],
        'inbound_silence_alert' => ['label' => 'تنبيه المدراء عند صمت الوارد من واتساب في وقت الدوام', 'capability' => 'C121', 'available' => true],
    ];

    /** @var array<string, array>|null بديل DEFINITIONS في الاختبارات وحدها (fake) */
    private static ?array $fake = null;

    /** للاختبارات: علم متاح لا يوجد بعد في الكود. null يعيد التعريفات الحقيقية */
    public static function fake(?array $definitions): void
    {
        self::$fake = $definitions;
    }

    /** @return array<string, array{label: string, capability: string, available: bool}> */
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
