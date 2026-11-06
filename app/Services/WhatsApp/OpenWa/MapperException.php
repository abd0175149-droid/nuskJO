<?php

namespace App\Services\WhatsApp\OpenWa;

/** الحمولة لا تتحول إلى نداء OpenWA (نوع لا مقابل له، أو ملف لم يعد موجوداً). الرمز من MetaErrorCatalog::LOCAL */
final class MapperException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
