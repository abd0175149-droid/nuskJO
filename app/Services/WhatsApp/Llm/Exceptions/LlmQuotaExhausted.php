<?php

namespace App\Services\WhatsApp\Llm\Exceptions;

use App\Services\WhatsApp\Llm\LlmException;

/** حصة أو فوترة منتهية (402، 429 يومي، سقف إنفاق): يفتح القاطع من أول مرة، وقد يحمل retryAfter */
final class LlmQuotaExhausted extends LlmException
{
    public const KIND = 'quota_exhausted';

    public function tripsImmediately(): bool
    {
        return true;
    }
}
