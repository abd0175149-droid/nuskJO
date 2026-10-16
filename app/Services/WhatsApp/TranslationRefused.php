<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\JsonResponse;

/** رفض ترجمة أو إرسال مترجم برمز ورسالة عربية للموظف وحالة HTTP (E29). لا نص رسالة ولا مفتاح فيه */
final class TranslationRefused extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $reason, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json(['ok' => false, 'code' => $this->reason, 'error' => $this->getMessage()] + $this->extra, $this->status);
    }
}
