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
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'wa_suspended' => 'boolean',
        'fail_handoff' => 'boolean',
        'tools_config' => 'array',
        'model_prices' => 'array',
        'wa_token' => 'encrypted',
        'wa_app_secret' => 'encrypted',
        'api_key' => 'encrypted',
    ];

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

    /** أولوية البيئة على القاعدة للأسرار */
    public function token(): ?string { return env('WA_TOKEN') ?: $this->wa_token; }
    public function phoneNumberId(): ?string { return env('WA_PHONE_NUMBER_ID') ?: $this->wa_phone_number_id; }
    public function verifyToken(): ?string { return env('WA_WEBHOOK_VERIFY_TOKEN') ?: $this->wa_verify_token; }
    public function appSecret(): ?string { return env('WA_APP_SECRET') ?: $this->wa_app_secret; }
    /** مفتاح المزوّد — البيئة أولاً ثم المحفوظ، وحسب المزوّد المختار */
    public function llmKey(): ?string
    {
        if ($this->provider === 'anthropic') {
            return env('ANTHROPIC_API_KEY') ?: $this->api_key;
        }

        return env('GOOGLE_API_KEY') ?: env('GEMINI_API_KEY') ?: $this->api_key;
    }

    public function envKeyPresent(): bool
    {
        return $this->provider === 'anthropic'
            ? !empty(env('ANTHROPIC_API_KEY'))
            : !empty(env('GOOGLE_API_KEY') ?: env('GEMINI_API_KEY'));
    }

    public function channelReady(): bool { return !empty($this->token()) && !empty($this->phoneNumberId()); }
    public function botReady(): bool { return $this->enabled && $this->channelReady() && !empty($this->llmKey()); }

    public function toolEnabled(string $key): bool
    {
        $cfg = $this->tools_config ?? [];
        return !array_key_exists($key, $cfg) || (bool) $cfg[$key];
    }
}
