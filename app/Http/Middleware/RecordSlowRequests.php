<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * سجل «الطلبات البطيئة و5xx» (E24): وسيط منتهٍ على المجموعة العامة، فيغطي الصفحات والواجهة ومسار الويبهوك
 * (خارج مجموعة web منذ E20). سطر واحد في storage/logs/perf.log لطلب أبطأ من config('ops.slow_ms') أو برد 500 فما فوق:
 * قالب المسار (api/whatsapp/conversations/{conversation}/messages) لا الرابط، واسم المسار والطريقة والحالة والمدة،
 * ورقم الطلب ومعرّف المستخدم رقماً. لا query ولا جسم ولا ترويسات ولا IP. لا عدّاد لكل طلب (الكاش على SQLite).
 */
class RecordSlowRequests
{
    private const STARTED = 'ops.started_ns';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::STARTED, hrtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $threshold = (int) config('ops.slow_ms', 1500);
        $started = $request->attributes->get(self::STARTED);
        // /up يرد 500 كل 30 ثانية ما دام عطل قائماً، وله سطره مرة في الساعة لكل رمز (E08) وaccess_log off في nginx
        if ($threshold <= 0 || !is_int($started) || $request->is('up')) {
            return;
        }
        $ms = (int) round((hrtime(true) - $started) / 1_000_000);
        $status = $response->getStatusCode();
        if ($ms <= $threshold && $status < 500) {
            return;
        }

        try {
            $route = $request->route();
            Log::channel('perf')->warning('slow_or_5xx', [
                'route' => $route?->uri() ?? 'unmatched',
                'name' => $route?->getName(),
                'method' => $request->method(),
                'status' => $status,
                'ms' => $ms,
                'request_id' => Context::get('request_id'),
                // المستخدم إن حُلّ أثناء الطلب فقط: لا قراءة جلسة ولا استعلام بعد الرد
                'user_id' => auth()->hasUser() ? auth()->id() : null,
            ]);
        } catch (\Throwable) {
            // السجل لا يُسقط الطلب ولا ما بعده
        }
    }
}
