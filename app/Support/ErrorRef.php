<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * خطأ لا يُعرض نصه للمستخدم: يُسجَّل كاملاً (منقّحاً عبر قنوات السجل) ويُعاد رقم مرجعي قصير يذكره المستخدم للدعم.
 */
final class ErrorRef
{
    public static function log(Throwable $e, string $event, array $context = []): string
    {
        $ref = substr((string) (Context::get('request_id') ?: Str::uuid()), 0, 8);
        Log::error($event, $context + ['ref' => $ref, 'exception' => $e]);

        return $ref;
    }
}
