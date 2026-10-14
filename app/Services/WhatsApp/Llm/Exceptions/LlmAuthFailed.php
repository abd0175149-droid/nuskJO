<?php

namespace App\Services\WhatsApp\Llm\Exceptions;

use App\Services\WhatsApp\Llm\LlmException;

/** مفتاح أو نموذج مرفوض (401/403/404، API_KEY_INVALID): يفتح القاطع من أول مرة */
final class LlmAuthFailed extends LlmException
{
    public const KIND = 'auth_failed';

    public function tripsImmediately(): bool
    {
        return true;
    }
}
