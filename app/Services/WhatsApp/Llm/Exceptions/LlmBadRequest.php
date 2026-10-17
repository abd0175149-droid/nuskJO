<?php

namespace App\Services\WhatsApp\Llm\Exceptions;

use App\Services\WhatsApp\Llm\LlmException;

/** طلب مرفوض لمحتواه (400، 413): لا يُحتسب، فلا الإعادة ولا القاطع يصلحانه */
final class LlmBadRequest extends LlmException
{
    public const KIND = 'bad_request';

    public function counts(): bool
    {
        return false;
    }
}
