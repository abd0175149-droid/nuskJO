<?php

namespace App\Services\WhatsApp\Llm\Exceptions;

use App\Services\WhatsApp\Llm\LlmException;

/** خنق مؤقت (429 بلا حصة يومية): يُحتسب، ومعه retryAfter؛ يُعاد داخلياً إن كان الانتظار ≤ 5 ثوانٍ */
final class LlmRateLimited extends LlmException
{
    public const KIND = 'rate_limited';

    public function retryable(): bool
    {
        return $this->retryAfter === null || $this->retryAfter <= 5;
    }
}
