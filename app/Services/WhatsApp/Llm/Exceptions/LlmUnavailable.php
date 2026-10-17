<?php

namespace App\Services\WhatsApp\Llm\Exceptions;

use App\Services\WhatsApp\Llm\LlmException;

/** خدمة غير متاحة (5xx، مهلة، شبكة): يُحتسب، ويُعاد داخلياً مرة */
final class LlmUnavailable extends LlmException
{
    public const KIND = 'unavailable';

    public function retryable(): bool
    {
        return true;
    }
}
