<?php

namespace App\Exceptions;

/** /up غير سليم. الرسالة رموز فقط (مثل queue_stale,backlog) ولا تُعرض؛ في dontReport، فالسطر يكتبه OpsHealth مرة في الساعة */
final class HealthCheckFailed extends \RuntimeException {}
