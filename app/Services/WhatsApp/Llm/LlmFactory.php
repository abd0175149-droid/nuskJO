<?php

namespace App\Services\WhatsApp\Llm;

class LlmFactory
{
    public const PROVIDERS = [
        'google' => 'Google Gemini',
        'anthropic' => 'Anthropic Claude',
    ];

    public static function make(?string $provider): LlmProvider
    {
        return match ($provider) {
            'anthropic' => new AnthropicProvider(),
            default => new GeminiProvider(),   // الافتراضي: جوجل
        };
    }
}
