<?php

namespace App\Models;

use App\Services\WhatsApp\Settings\SettingsSchema;
use App\Services\WhatsApp\WaLog;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class WaBotSetting extends Model
{
    protected $table = 'wa_bot_settings';

    protected $fillable = [
        'enabled', 'wa_suspended',
        'wa_token', 'wa_phone_number_id', 'wa_verify_token', 'wa_app_secret',
        'provider', 'model', 'api_key', 'system_prompt', 'knowledge_base',
        'context_messages', 'pause_minutes', 'max_tool_loops', 'rate_limit_per_hour',
        'fail_message', 'fail_handoff', 'tools_config', 'model_prices', 'cache_discount',
        'max_reply_age_minutes', 'reliability',
        'wa_app_id', 'wa_credentials_status', 'wa_channel_health', 'wa_last_error',
        // E10: settings_version خارج القائمة عمداً (يرفعه SettingsWriter وحده)
        'wa_verify_token_hash', 'wa_verify_token_rotated_at', 'wa_token_rotated_at', 'wa_app_secret_rotated_at', 'api_key_rotated_at',
        'wa_suspended_at', 'wa_suspended_by', 'wa_suspended_source', 'wa_suspended_reason', 'feature_flags',
        // E12: قاطع المزوّد والمزوّد الاحتياطي
        'breaker_mode', 'breaker_threshold', 'breaker_window_minutes', 'breaker_open_minutes',
        'fallback_provider', 'fallback_model', 'fallback_api_key', 'fallback_api_key_rotated_at',
        // E35: إشعار القراءة ومؤشر الكتابة (off | on_reply | on_open)
        'send_read_receipts',
        // E37: وضع الردود الحتمية (shadow | live) والمكوّنات الرسمية في واتساب
        'auto_rules_mode', 'conversational_automation',
        // E22: صحة القناة كآلة حالات (يكتبها ChannelHealth وحده عبر SettingsWriter)
        'wa_health', 'wa_health_reason', 'wa_health_source', 'wa_health_until', 'wa_health_checked_at', 'wa_health_changed_at',
        'wa_health_unblock_suggested', 'wa_health_meta',
        // E39: المكالمات والتعايش (مساراتها في بطاقتيهما، وكلها عبر SettingsWriter)
        'calls_answer_mode', 'calls_auto_reject', 'calls_followup_text', 'calls_followup_text_en', 'calling_snapshot',
        'echo_pause_minutes', 'coexistence_holder_user_id',
        // E24: حد تنبيه الصادر اليومي، وسقف الإرسال المبادر، وسعر رسالة الخدمة (للتقدير)
        'wa_daily_alert_threshold', 'wa_system_daily_cap', 'wa_service_rate_usd',
        // E36: نموذج طلب التسعير (معرّف الـ Flow المنشور ووضعه draft | published)
        'wa_quote_flow_id', 'wa_quote_flow_mode',
    ];

    /** دفاعاً إن سُلسل النموذج يوماً: لا سر في toArray/JSON */
    protected $hidden = ['wa_token', 'wa_app_secret', 'api_key', 'wa_verify_token', 'wa_verify_token_hash', 'fallback_api_key'];

    /** أعلام موثوقية الرد (E07) وقيمها الافتراضية؛ تُبدَّل وقت التشغيل بـ whatsapp:reliability */
    public const RELIABILITY_FLAGS = [
        'watermark' => true,     // بوابة «وارد بعد آخر ما خُدم» بدل «آخر رسالة من العميل»
        'stale_gate' => true,    // لا رد آلي على وارد أقدم من max_reply_age_minutes
        'reconcile' => false,    // المُصالِح كل دقيقة
        'webhook_5xx' => false,  // 503 لأعطال القاعدة في الويبهوك (بعد WAL)
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'wa_suspended' => 'boolean',
        'fail_handoff' => 'boolean',
        'tools_config' => 'array',
        'model_prices' => 'array',
        'reliability' => 'array',
        'wa_credentials_status' => 'array',
        'wa_channel_health' => 'array',
        'wa_last_error' => 'array',
        'wa_token' => 'encrypted',
        'wa_app_secret' => 'encrypted',
        'api_key' => 'encrypted',
        'fallback_api_key' => 'encrypted',
        'fallback_api_key_rotated_at' => 'datetime',
        'feature_flags' => 'array',
        'wa_verify_token_rotated_at' => 'datetime',
        'wa_token_rotated_at' => 'datetime',
        'wa_app_secret_rotated_at' => 'datetime',
        'api_key_rotated_at' => 'datetime',
        'wa_suspended_at' => 'datetime',
        // E29
        'llm_terms_confirmed_at' => 'datetime',
        'conversational_automation' => 'array',
        'wa_health_until' => 'datetime',
        'wa_health_checked_at' => 'datetime',
        'wa_health_changed_at' => 'datetime',
        'wa_health_unblock_suggested' => 'boolean',
        'wa_health_meta' => 'array',
        // E39
        'calling_snapshot' => 'array',
        'calls_auto_reject' => 'boolean',
        'echo_pause_minutes' => 'integer',
        'coexistence_holder_user_id' => 'integer',
        'wa_daily_alert_threshold' => 'integer',
        'wa_system_daily_cap' => 'integer',
        'wa_service_rate_usd' => 'float',
    ];

    protected static function booted(): void
    {
        // المنقّح يحفظ الأسرار المعروفة في الذاكرة؛ سرّ جديد يجب أن يُنقّح من أول سجل بعده
        static::saved(fn () => \App\Support\SecretRedactor::forget());
    }

    /** الصف الوحيد — يُنشأ عند أول استدعاء بقيم SettingsSchema::defaults() (مصدرها الوحيد) */
    public static function current(): self
    {
        $settings = static::firstOrCreate([], SettingsSchema::defaults());

        // صف جديد: قيم القاعدة الافتراضية (settings_version) تُقرأ بدل أن تبقى null في النموذج
        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
    }

    /** أولوية البيئة (عبر config/services.php) على القاعدة للأسرار */
    public function token(): ?string { return config('services.whatsapp.token') ?: $this->secret('wa_token'); }
    public function phoneNumberId(): ?string { return config('services.whatsapp.phone_number_id') ?: $this->wa_phone_number_id; }
    public function appSecret(): ?string { return config('services.whatsapp.app_secret') ?: $this->secret('wa_app_secret'); }

    /**
     * هاش رمز التحقق الذي تُقارن به المصافحة: من البيئة إن ضُبطت، وإلا المحفوظ (E10 يخزّن الهاش وحده
     * للرمز المولَّد)، وإلا هاش النص الصريح القديم حتى يمسحه whatsapp:settings-finalize.
     */
    public function verifyTokenHash(): ?string
    {
        $env = (string) config('services.whatsapp.verify_token');
        if ($env !== '') {
            return hash('sha256', $env);
        }
        if (!empty($this->wa_verify_token_hash)) {
            return (string) $this->wa_verify_token_hash;
        }

        return !empty($this->wa_verify_token) ? hash('sha256', (string) $this->wa_verify_token) : null;
    }

    /**
     * سر مشفّر بلا 500 إن تعذّر فكّه (مفتاح التطبيق تغيّر بلا APP_PREVIOUS_KEYS): null وسطر خطأ مرة في الساعة.
     * الويبهوك يرد 403 حينها (ميتا تعيد 7 أيام)، وصفحة الإعدادات تُظهر «تعذّر فك التشفير».
     */
    private function secret(string $column): ?string
    {
        try {
            return $this->{$column};
        } catch (DecryptException) {
            if (Cache::add("wa:decrypt_failed:{$column}", 1, 3600)) {
                WaLog::error('settings.decrypt_failed', ['field' => $column]);
            }

            return null;
        }
    }

    /** null: لا قيمة محفوظة؛ true/false: هل تُفك بمفاتيح التطبيق الحالية */
    public function decryptOk(string $column): ?bool
    {
        $raw = $this->getAttributes()[$column] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $this->{$column};

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    /** الإيقاف منطق OR لا أولوية: يكفي أحد المصدرين (القاعدة أو WA_SUSPENDED) */
    public function sendingSuspended(): bool
    {
        return (bool) $this->wa_suspended || (bool) config('services.whatsapp.suspended');
    }

    /** من أوقف الإرسال؟ env | manual | meta_event | graph_error | restore | null (يعمل) */
    public function suspendSource(): ?string
    {
        if ((bool) config('services.whatsapp.suspended')) {
            return 'env';
        }

        return $this->wa_suspended ? ($this->wa_suspended_source ?: 'manual') : null;
    }

    /**
     * مصدر كل مفتاح له متغير بيئة: env | db | default | unset، واسم المتغير، وهل تظلل البيئة قيمة محفوظة،
     * وهل يُفك السر المحفوظ. لا قيمة سرية في المخرجات.
     *
     * @return array<string, array{source: string, env_var: ?string, db_shadowed: bool, decrypt_ok: ?bool}>
     */
    public function sources(): array
    {
        $out = [];
        foreach (SettingsSchema::FIELDS as $key => $f) {
            if (!isset($f['env'])) {
                continue;
            }
            $env = match ($key) {
                'api_key' => self::envKeyFor($this->provider),
                default => config($f['env']),
            };
            $envSet = $key === 'wa_suspended' ? (bool) $env : ($env !== null && $env !== '' && $env !== false);
            $raw = $this->getAttributes()[$key] ?? null;
            $dbSet = match ($key) {
                'wa_suspended' => (bool) $this->wa_suspended,
                'wa_verify_token' => !empty($raw) || !empty($this->wa_verify_token_hash),
                default => $raw !== null && $raw !== '',
            };
            $out[$key] = [
                'source' => $envSet ? 'env' : ($dbSet ? 'db' : (SettingsSchema::default($key) !== null ? 'default' : 'unset')),
                'env_var' => $f['env_var'] ?? null,
                'db_shadowed' => $envSet && $dbSet && $key !== 'wa_suspended',
                'decrypt_ok' => in_array($key, ['wa_token', 'wa_app_secret', 'api_key'], true) ? $this->decryptOk($key) : null,
            ];
        }

        return $out;
    }
    /** معرّف تطبيق ميتا (عام): لفحص الرمز بـ debug_token */
    public function appId(): ?string { return config('services.whatsapp.app_id') ?: $this->wa_app_id; }
    public function metaChecksReady(): bool { return !empty($this->token()) && !empty($this->appId()) && !empty($this->appSecret()); }
    /** مفتاح المزوّد — البيئة أولاً ثم المحفوظ، وحسب المزوّد المختار */
    public function llmKey(): ?string
    {
        return self::envKeyFor($this->provider) ?: $this->secret('api_key');
    }

    /**
     * المزوّد الاحتياطي (E12): مفتاح بيئة مزوّده أولاً ثم المحفوظ. جاهز إن اكتمل واختلف عن الأساسي
     * (نموذج Gemini آخر بالمفتاح نفسه لا يضيف معالِجاً جديداً للبيانات).
     */
    public function fallbackKey(): ?string
    {
        if (!$this->fallback_provider) {
            return null;
        }

        return self::envKeyFor($this->fallback_provider) ?: ($this->secret('fallback_api_key') ?: null);
    }

    public function fallbackReady(): bool
    {
        return filled($this->fallback_provider) && filled($this->fallback_model) && filled($this->fallbackKey())
            && [$this->fallback_provider, $this->fallback_model] !== [$this->provider, $this->model];
    }

    public function envKeyPresent(): bool
    {
        return !empty(self::envKeyFor($this->provider));
    }

    /** مفتاح المزوّد من البيئة إن وُجد */
    public static function envKeyFor(?string $provider): ?string
    {
        return $provider === 'anthropic'
            ? config('services.llm.anthropic_key')
            : config('services.llm.google_key');
    }

    public function channelReady(): bool { return !empty($this->token()) && !empty($this->phoneNumberId()); }
    public function botReady(): bool { return $this->enabled && $this->channelReady() && !empty($this->llmKey()); }

    public function flag(string $key): bool
    {
        return (bool) (($this->reliability ?? [])[$key] ?? self::RELIABILITY_FLAGS[$key] ?? false);
    }

    /** ما لا يُطفأ (handoff_to_human، E15.4) مفعّل دائماً مهما كان في tools_config القديم */
    public function toolEnabled(string $key): bool
    {
        if (!\App\Services\WhatsApp\BotTools::disableable($key)) {
            return true;
        }
        $cfg = $this->tools_config ?? [];

        // مفتاح غائب: حالة الأداة الافتراضية (مفعّلة، إلا ما شُحن مطفأً في E33)
        return array_key_exists($key, $cfg) ? (bool) $cfg[$key] : \App\Services\WhatsApp\BotTools::defaultEnabled($key);
    }
}
