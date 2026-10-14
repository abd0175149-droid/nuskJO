<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    ];

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
        'wa_token' => 'encrypted',
        'wa_app_secret' => 'encrypted',
        'api_key' => 'encrypted',
    ];

    protected static function booted(): void
    {
        // المنقّح يحفظ الأسرار المعروفة في الذاكرة؛ سرّ جديد يجب أن يُنقّح من أول سجل بعده
        static::saved(fn () => \App\Support\SecretRedactor::forget());
    }

    /** الصف الوحيد — يُنشأ بقيم افتراضية آمنة عند أول استدعاء */
    public static function current(): self
    {
        return static::firstOrCreate([], [
            'enabled' => false,
            'wa_suspended' => false,
            'provider' => 'google',
            'model' => 'gemini-2.5-flash',
            'context_messages' => 20,
            'pause_minutes' => 30,
            'max_tool_loops' => 4,
            'rate_limit_per_hour' => 20,
            'fail_handoff' => true,
            'fail_message' => 'عذراً، حدث خلل مؤقّت. سيتواصل معك أحد موظفينا حالاً.',
        ]);
    }

    /** أولوية البيئة (عبر config/services.php) على القاعدة للأسرار */
    public function token(): ?string { return config('services.whatsapp.token') ?: $this->wa_token; }
    public function phoneNumberId(): ?string { return config('services.whatsapp.phone_number_id') ?: $this->wa_phone_number_id; }
    public function verifyToken(): ?string { return config('services.whatsapp.verify_token') ?: $this->wa_verify_token; }
    public function appSecret(): ?string { return config('services.whatsapp.app_secret') ?: $this->wa_app_secret; }
    /** مفتاح المزوّد — البيئة أولاً ثم المحفوظ، وحسب المزوّد المختار */
    public function llmKey(): ?string
    {
        return self::envKeyFor($this->provider) ?: $this->api_key;
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

    public function toolEnabled(string $key): bool
    {
        $cfg = $this->tools_config ?? [];
        return !array_key_exists($key, $cfg) || (bool) $cfg[$key];
    }
}
