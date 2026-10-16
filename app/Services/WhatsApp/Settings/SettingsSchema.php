<?php

namespace App\Services\WhatsApp\Settings;

use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\Llm\LlmFactory;
use App\Support\AuditRedactor;
use Illuminate\Validation\Rule;

/**
 * سجل حقول إعدادات البوت (E10): منه وحده تُشتق القيم الافتراضية (WaBotSetting::current) وقواعد الحفظ
 * الجزئي والحقول الرقمية في صفحة الإعدادات، وفرق التدقيق. حقل جديد يُضاف هنا أولاً.
 *
 * type: bool|int|string|text|enum|map|secret|readonly
 * patchable: يُقبل في PATCH /whatsapp/settings. الأسرار والإيقاف لها مساراتها، والقراءة فقط لا تُكتب من الصفحة.
 * sensitive: يتطلب confirm=true، ويُدقَّق بخطورة warn (كل حفظ إعدادات warn أصلاً).
 * env: مفتاح config الذي تتقدّم قيمته على المحفوظ (config/services.php هو المكان الوحيد لقراءة env)، وenv_var للعرض.
 * ui: auto (يُولَّد في الصفحة من هذا السجل) | custom (له مكوّنه) | none.
 */
final class SettingsSchema
{
    public const FIELDS = [
        'enabled' => ['type' => 'bool', 'default' => false, 'patchable' => true, 'group' => 'status', 'ui' => 'custom', 'label' => 'تشغيل بوت العملاء'],
        'wa_phone_number_id' => ['type' => 'string', 'regex' => '/^\d{5,32}$/', 'nullable' => true, 'default' => null, 'patchable' => true, 'sensitive' => true,
            'env' => 'services.whatsapp.phone_number_id', 'env_var' => 'WA_PHONE_NUMBER_ID', 'group' => 'meta', 'ui' => 'custom', 'label' => 'Phone Number ID'],
        // معرّف حساب الأعمال (E20): يدوي هنا أو WA_WABA_ID، وبه يفرض سياج الويبهوك. يكتبه أيضاً ما يُتعلَّم من entry.id
        // ما لم يُضبط يدوياً (wa_waba_id_source). خاطئه يُسقط رسائل العملاء عند الفرض، فهو حساس
        'wa_waba_id' => ['type' => 'string', 'regex' => '/^\d{5,32}$/', 'nullable' => true, 'default' => null, 'patchable' => true, 'sensitive' => true,
            'env' => 'services.whatsapp.waba_id', 'env_var' => 'WA_WABA_ID', 'group' => 'meta', 'ui' => 'custom', 'label' => 'معرّف حساب الأعمال (WABA)'],
        'wa_app_id' => ['type' => 'string', 'regex' => '/^\d{5,20}$/', 'nullable' => true, 'default' => null, 'patchable' => true,
            'env' => 'services.whatsapp.app_id', 'env_var' => 'WA_APP_ID', 'group' => 'meta', 'ui' => 'custom', 'label' => 'App ID'],
        'provider' => ['type' => 'enum', 'values' => 'providers', 'default' => 'google', 'patchable' => true, 'sensitive' => true, 'group' => 'ai', 'ui' => 'custom', 'label' => 'مزوّد الذكاء'],
        // مقطع مسار عند المزوّد: حروف وأرقام و . _ - فقط (E05)
        'model' => ['type' => 'string', 'regex' => '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', 'default' => 'gemini-2.5-flash', 'patchable' => true, 'group' => 'ai', 'ui' => 'custom', 'label' => 'النموذج'],
        // null = النص الافتراضي الحي من BotEngine::DEFAULT_PROMPT (يتحدّث مع الكود)
        'system_prompt' => ['type' => 'text', 'max' => 20000, 'nullable' => true, 'default' => null, 'patchable' => true, 'group' => 'persona', 'ui' => 'custom', 'label' => 'شخصية البوت'],
        'context_messages' => ['type' => 'int', 'min' => 4, 'max' => 60, 'default' => 20, 'patchable' => true, 'group' => 'ai', 'ui' => 'auto',
            'label' => 'رسائل السياق', 'help' => 'كم رسالة سابقة يقرأها البوت قبل الرد'],
        'pause_minutes' => ['type' => 'int', 'min' => 1, 'max' => 1440, 'default' => 30, 'patchable' => true, 'group' => 'ai', 'ui' => 'auto',
            'label' => 'إيقاف البوت بعد رد موظف (دقائق)', 'help' => 'يصمت البوت في المحادثة هذه المدة بعد آخر رد بشري'],
        'max_reply_age_minutes' => ['type' => 'int', 'min' => 2, 'max' => 120, 'default' => 10, 'patchable' => true, 'group' => 'ai', 'ui' => 'auto',
            'label' => 'أقصى عمر للرد (دقائق)', 'help' => 'وارد أقدم من هذا لا يرد عليه البوت، ويُرفع للموظفين'],
        'max_tool_loops' => ['type' => 'int', 'min' => 1, 'max' => 8, 'default' => 4, 'patchable' => true, 'group' => 'ai', 'ui' => 'auto',
            'label' => 'أقصى استدعاءات أدوات للرد', 'help' => 'حد الجولات بين النموذج والأدوات في الرد الواحد'],
        'rate_limit_per_hour' => ['type' => 'int', 'min' => 1, 'max' => 200, 'default' => 20, 'patchable' => true, 'group' => 'ai', 'ui' => 'auto',
            'label' => 'حد ردود البوت للعميل في الساعة', 'help' => 'بعده يُحوَّل العميل لموظف'],
        'fail_message' => ['type' => 'text', 'max' => 500, 'nullable' => true, 'default' => 'عذراً، حدث خلل مؤقّت. سيتواصل معك أحد موظفينا حالاً.', 'patchable' => true,
            'group' => 'failure', 'ui' => 'custom', 'label' => 'رسالة الخلل'],
        'fail_handoff' => ['type' => 'bool', 'default' => true, 'patchable' => true, 'group' => 'failure', 'ui' => 'custom', 'label' => 'تحويل لموظف عند الخلل'],
        // خريطة أداة => تشغيل. مفتاح غائب = الأداة مفعّلة (WaBotSetting::toolEnabled)
        'tools_config' => ['type' => 'map', 'keys' => 'tools', 'nullable' => true, 'default' => null, 'patchable' => true, 'group' => 'tools', 'ui' => 'custom', 'label' => 'أدوات البوت'],
        // قاطع مزوّد الذكاء (E12): off لا يحسب، shadow يحسب ويسجّل ولا يحجب، on يحوّل للموظفين أثناء العطل
        'breaker_mode' => ['type' => 'enum', 'values' => 'breaker_modes', 'default' => 'shadow', 'patchable' => true, 'group' => 'failure', 'ui' => 'custom', 'label' => 'وضع قاطع المزوّد'],
        'breaker_threshold' => ['type' => 'int', 'min' => 2, 'max' => 10, 'default' => 3, 'patchable' => true, 'group' => 'failure', 'ui' => 'custom',
            'label' => 'إخفاقات متتالية تفتح القاطع'],
        'breaker_window_minutes' => ['type' => 'int', 'min' => 1, 'max' => 30, 'default' => 5, 'patchable' => true, 'group' => 'failure', 'ui' => 'custom',
            'label' => 'نافذة عدّ الإخفاقات (دقائق)'],
        'breaker_open_minutes' => ['type' => 'int', 'min' => 2, 'max' => 120, 'default' => 10, 'patchable' => true, 'group' => 'failure', 'ui' => 'custom',
            'label' => 'مدة وضع التحويل (دقائق)'],
        // مزوّد احتياطي اختياري (معطّل حتى يُضبط): مزوّد آخر يُذكر في صفحة الخصوصية تلقائياً
        'fallback_provider' => ['type' => 'enum', 'values' => 'providers', 'nullable' => true, 'default' => null, 'patchable' => true, 'sensitive' => true,
            'group' => 'ai', 'ui' => 'custom', 'label' => 'المزوّد الاحتياطي'],
        'fallback_model' => ['type' => 'string', 'regex' => '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', 'nullable' => true, 'default' => null, 'patchable' => true,
            'group' => 'ai', 'ui' => 'custom', 'label' => 'نموذج الاحتياطي'],
        'model_prices' => ['type' => 'readonly', 'default' => null, 'ui' => 'none', 'label' => 'أسعار النموذج (صفحة التحليلات)'],
        'cache_discount' => ['type' => 'readonly', 'default' => null, 'ui' => 'none', 'label' => 'خصم الكاش (صفحة التحليلات)'],

        // الأسرار: مساراتها في E10 (تعيين بسبب، ومسح، وتوليد)، ولا تعود للمتصفح أبداً
        'wa_token' => ['type' => 'secret', 'env' => 'services.whatsapp.token', 'env_var' => 'WA_TOKEN', 'group' => 'meta', 'ui' => 'custom', 'label' => 'رمز ميتا (WA_TOKEN)'],
        'wa_app_secret' => ['type' => 'secret', 'env' => 'services.whatsapp.app_secret', 'env_var' => 'WA_APP_SECRET', 'group' => 'meta', 'ui' => 'custom', 'label' => 'App Secret'],
        'api_key' => ['type' => 'secret', 'env' => 'llm', 'env_var' => 'GOOGLE_API_KEY | ANTHROPIC_API_KEY', 'group' => 'ai', 'ui' => 'custom', 'label' => 'مفتاح المزوّد'],
        'fallback_api_key' => ['type' => 'secret', 'group' => 'ai', 'ui' => 'custom', 'label' => 'مفتاح المزوّد الاحتياطي'],
        'wa_verify_token' => ['type' => 'secret', 'env' => 'services.whatsapp.verify_token', 'env_var' => 'WA_WEBHOOK_VERIFY_TOKEN', 'group' => 'meta', 'ui' => 'custom', 'label' => 'رمز التحقق من الويبهوك'],

        // الإيقاف الطارئ: مسار خاص بسبب وتأكيد
        'wa_suspended' => ['type' => 'bool', 'default' => false, 'patchable' => false, 'env' => 'services.whatsapp.suspended', 'env_var' => 'WA_SUSPENDED', 'group' => 'status', 'ui' => 'custom', 'label' => 'إيقاف كل الإرسال'],

        // للقراءة: نسخة قديمة من قاعدة المعرفة (الحالية في wa_knowledge_entries)، وما يكتبه التشغيل نفسه
        'knowledge_base' => ['type' => 'readonly', 'default' => null, 'ui' => 'none', 'label' => 'قاعدة المعرفة القديمة'],
        'reliability' => ['type' => 'readonly', 'default' => null, 'ui' => 'none', 'label' => 'أعلام موثوقية الرد (whatsapp:reliability)'],
        'wa_credentials_status' => ['type' => 'readonly', 'default' => null, 'ui' => 'none', 'label' => 'آخر فحص للرمز'],
        'wa_channel_health' => ['type' => 'readonly', 'default' => null, 'ui' => 'none', 'label' => 'آخر فحص للقناة'],
        // يكتبه MetaErrorReactor مباشرة (عمود واحد خارج SettingsWriter) فلا يدخل حساب تعارض النسخة
        'wa_last_error' => ['type' => 'readonly', 'default' => null, 'ui' => 'custom', 'label' => 'آخر خطأ من ميتا'],
    ];

    /** أعمدة يكتبها الكاتب نفسه مع فعلها (تدوير سر، إيقاف، علم)، لا حقول إعدادات تُعرض أو تُحفظ من الصفحة */
    public const METADATA = [
        'wa_verify_token_hash', 'wa_verify_token_rotated_at', 'wa_token_rotated_at', 'wa_app_secret_rotated_at', 'api_key_rotated_at',
        'wa_suspended_at', 'wa_suspended_by', 'wa_suspended_source', 'wa_suspended_reason', 'feature_flags',
        'fallback_api_key_rotated_at', 'wa_waba_id_source', 'wa_display_phone',
    ];

    /** النصوص الطويلة: طولها وبصمتها في التدقيق لا نصها */
    private const LONG_TEXT = ['system_prompt', 'knowledge_base'];

    /** @return array<string, mixed> قيم الإنشاء الأول للصف */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::FIELDS as $key => $f) {
            if (array_key_exists('default', $f) && $f['type'] !== 'readonly') {
                $out[$key] = $f['default'];
            }
        }

        return $out;
    }

    public static function default(string $key): mixed
    {
        return self::FIELDS[$key]['default'] ?? null;
    }

    /** @return list<string> */
    public static function patchableKeys(): array
    {
        return array_keys(array_filter(self::FIELDS, fn (array $f) => ($f['patchable'] ?? false) === true));
    }

    /** @return list<string> */
    public static function secretKeys(): array
    {
        return array_keys(array_filter(self::FIELDS, fn (array $f) => $f['type'] === 'secret'));
    }

    public static function isSensitive(string $key): bool
    {
        return (bool) (self::FIELDS[$key]['sensitive'] ?? false);
    }

    /** قواعد PATCH: مفتاح غير مسموح يُرفض (array:keys)، وكل مُرسل nullable (null = الافتراضي) */
    public static function patchRules(array $changes): array
    {
        $rules = ['changes' => ['required', 'array:'.implode(',', self::patchableKeys())]];
        foreach (array_keys($changes) as $key) {
            if (!is_string($key) || !in_array($key, self::patchableKeys(), true)) {
                continue;
            }
            $rules["changes.{$key}"] = ['nullable', ...self::fieldRules($key)];
        }
        if (array_key_exists('tools_config', $changes)) {
            $rules['changes.tools_config.*'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    /** @return list<mixed> */
    public static function fieldRules(string $key): array
    {
        $f = self::FIELDS[$key];

        return match ($f['type']) {
            'bool' => ['boolean'],
            'int' => ['integer', 'min:'.$f['min'], 'max:'.$f['max']],
            'string' => array_values(array_filter(['string', 'max:64', isset($f['regex']) ? 'regex:'.$f['regex'] : null])),
            'text' => ['string', 'max:'.$f['max']],
            'enum' => [Rule::in(self::enumValues($key))],
            'map' => ['array:'.implode(',', BotTools::names())],
            default => ['prohibited'],
        };
    }

    /** @return list<string> */
    public static function enumValues(string $key): array
    {
        return match (self::FIELDS[$key]['values'] ?? null) {
            'providers' => array_keys(LlmFactory::PROVIDERS),
            'breaker_modes' => ['off', 'shadow', 'on'],
            default => [],
        };
    }

    /** الحقول التي تولّدها الصفحة من السجل نفسه؛ بلا أي قيمة */
    public static function uiFields(): array
    {
        $out = [];
        foreach (self::FIELDS as $key => $f) {
            if (($f['ui'] ?? null) === 'auto') {
                $out[] = array_intersect_key($f, array_flip(['type', 'min', 'max', 'default', 'label', 'help', 'group'])) + ['key' => $key];
            }
        }

        return $out;
    }

    /**
     * فرق التدقيق بين حالتين: الأسرار بأسمائها وبصمة قصيرة للقيمة الجديدة، والنصوص الطويلة بطولها وبصمتها،
     * والباقي from/to. أسماء المفاتيح لا تحوي token/secret، وإلا حجبها AuditRedactor.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function auditDiff(array $before, array $after): array
    {
        $changed = [];
        $credentials = [];
        foreach (self::FIELDS as $key => $f) {
            if (!array_key_exists($key, $after) && !array_key_exists($key, $before)) {
                continue;
            }
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;
            if ($b == $a) {
                continue;
            }
            if ($f['type'] === 'secret') {
                $credentials[] = ['field' => $key, 'set' => $a !== null && $a !== '', 'fp' => $a === null || $a === '' ? null : substr(hash('sha256', (string) $a), 0, 8)];
            } elseif (in_array($key, self::LONG_TEXT, true)) {
                $changed[$key] = ['from' => AuditRedactor::fingerprint($b), 'to' => AuditRedactor::fingerprint($a)];
            } else {
                $changed[$key] = ['from' => self::short($b), 'to' => self::short($a)];
            }
        }

        return array_filter(['changed' => $changed, 'credentials' => $credentials]);
    }

    private static function short(mixed $v): mixed
    {
        return is_string($v) && mb_strlen($v) > 200 ? mb_substr($v, 0, 200).'…' : $v;
    }
}
