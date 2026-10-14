<?php

namespace App\Exceptions;

use RuntimeException;

/** حفظ إعدادات بنسخة قديمة: غيّرها غيرك (مدير آخر، أو المساعد الإداري، أو إيقاف آلي) بعد فتح الصفحة */
class StaleSettingsException extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('تغيّرت الإعدادات بعد فتح الصفحة');
    }
}
