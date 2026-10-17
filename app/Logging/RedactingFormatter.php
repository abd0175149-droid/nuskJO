<?php

namespace App\Logging;

use App\Support\SecretRedactor;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * يغلّف منسّق القناة وينقّح النص النهائي: الطبقة الوحيدة التي ترى رسالة الاستثناء وتتبّعه كما سيُكتبان.
 */
class RedactingFormatter implements FormatterInterface
{
    public function __construct(private FormatterInterface $inner) {}

    public function format(LogRecord $record)
    {
        return self::clean($this->inner->format($record));
    }

    public function formatBatch(array $records)
    {
        return self::clean($this->inner->formatBatch($records));
    }

    private static function clean(mixed $formatted): mixed
    {
        return is_string($formatted) ? SecretRedactor::clean($formatted) : $formatted;
    }
}
