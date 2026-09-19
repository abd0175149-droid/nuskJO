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
        'fail_message', 'fail_handoff', 'tools_config',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'wa_suspended' => 'boolean',
        'fail_handoff' => 'boolean',
        'tools_config' => 'array',
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
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
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
    public function llmKey(): ?string { return env('ANTHROPIC_API_KEY') ?: $this->api_key; }

    public function channelReady(): bool { return !empty($this->token()) && !empty($this->phoneNumberId()); }
    public function botReady(): bool { return $this->enabled && $this->channelReady() && !empty($this->llmKey()); }

    public function toolEnabled(string $key): bool
    {
        $cfg = $this->tools_config ?? [];
        return !array_key_exists($key, $cfg) || (bool) $cfg[$key];
    }
}
