<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * رفض رسالة صوتية من الموظف قبل الرفع (E34): رمز ثابت للواجهة وحالة HTTP ونص عربي. لا مسار ملف ولا stderr فيه أبداً،
 * فالتفاصيل التقنية في سجل الواتساب وحده.
 */
abstract class VoiceNoteException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
