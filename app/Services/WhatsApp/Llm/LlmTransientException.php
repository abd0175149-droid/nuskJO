<?php

namespace App\Services\WhatsApp\Llm;

/**
 * خلل عابر عند المزوّد (ازدحام، خطأ خادم، مهلة أو شبكة): تستحق محاولة ثانية واحدة إن بقي وقت.
 * غيره (مفتاح خاطئ، نموذج غير موجود، طلب مرفوض) لا يتغيّر بالإعادة.
 */
final class LlmTransientException extends \RuntimeException
{
    /** 529: ازدحام Anthropic */
    public const STATUSES = [429, 500, 502, 503, 504, 529];

    public static function isTransient(int $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }
}
