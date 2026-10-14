<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * رقم مرجعي لكل طلب: يُكتب في كل سطر سجل (Context)، وينتقل مع الوظائف إلى الطابور، ويُحفظ في التدقيق،
 * ويُعاد في الترويسة X-Request-ID وفي صفحة الخطأ. المصدر: X-Request-ID أو CF-Ray إن صحّا، وإلا UUID.
 */
class AssignRequestId
{
    private const PATTERN = '/^[A-Za-z0-9\-]{8,64}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $id = collect([$request->header('X-Request-ID'), $request->header('CF-Ray')])
            ->first(fn ($v) => is_string($v) && preg_match(self::PATTERN, $v)) ?? (string) Str::uuid();

        Context::add('request_id', $id);

        // ترويسة توجيه من مصدر غير موثوق: أحد يطرق المنفذ مباشرة متجاوزاً النفق (تنبيه مرة في الساعة)
        if ($request->headers->has('X-Forwarded-For') && !$request->isFromTrustedProxy()
            && Cache::add('proxy:untrusted:'.now()->format('YmdH'), 1, 3600)) {
            Log::warning('proxy.untrusted_forwarded', ['remote' => $request->server('REMOTE_ADDR')]);
        }

        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);

        return $response;
    }
}
