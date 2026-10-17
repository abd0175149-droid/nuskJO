<?php

namespace App\Logging;

use App\Support\SecretRedactor;
use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\LogRecord;

/**
 * tap لقنوات السجل، طبقتان:
 *  - معالج ينقّح الرسالة والسياق والإضافات (النصوص، وقيم المفاتيح السرية بالاسم) قبل التنسيق؛
 *  - غلاف لمنسّق كل handler ينقّح النص النهائي، ومعه رسالة الاستثناء وتتبّعه.
 */
class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        $monolog->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
            message: SecretRedactor::clean($record->message),
            context: SecretRedactor::cleanArray($record->context),
            extra: SecretRedactor::cleanArray($record->extra),
        ));

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface && !$handler->getFormatter() instanceof RedactingFormatter) {
                $handler->setFormatter(new RedactingFormatter($handler->getFormatter()));
            }
        }
    }
}
