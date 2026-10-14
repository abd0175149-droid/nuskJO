<?php

namespace App\Logging;

use App\Support\SecretRedactor;
use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;

/**
 * tap لقنوات السجل: الرسالة وقيم السياق النصية تمر على SecretRedactor قبل الكتابة.
 * الاستثناء في السياق يُكتب نصاً منقّحاً (الصنف والرسالة والموضع والتتبّع)، لأن رسالته قد تحوي رابطاً بمفتاح.
 */
class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
            message: SecretRedactor::clean($record->message),
            context: self::cleanValue($record->context, 0),
        ));
    }

    private static function cleanValue(mixed $value, int $depth): mixed
    {
        if (is_string($value)) {
            return SecretRedactor::clean($value);
        }
        if ($value instanceof Throwable) {
            return SecretRedactor::clean(sprintf("%s: %s at %s:%d\n%s",
                $value::class, $value->getMessage(), $value->getFile(), $value->getLine(), $value->getTraceAsString()));
        }
        if (is_array($value) && $depth < 5) {
            foreach ($value as $key => $item) {
                $value[$key] = self::cleanValue($item, $depth + 1);
            }
        }

        return $value;
    }
}
