<?php

namespace App\Http\Middleware;

use App\Support\Csp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * سياسة أمن المحتوى بـ nonce لكل صفحة HTML من مجموعة web (E26.3)، تقارير فقط افتراضياً (config/csp.php).
 * nonce الطلب يصل كل وسم @vite عبر Vite::useCspNonce، وسكربت الثيم و@routes عبر Vite::cspNonce().
 * لا تُضاف: للملفات والتدفقات، ولما يحمل سياسته (عرض المرفقات، ومعاينة البطاقة بسياستها الأشد)، ولغير HTML.
 * الويبهوك و/csp-report خارج مجموعة web أصلاً (routes/webhooks.php).
 */
final class SecurityHeaders
{
    /** صفحات لها سياستها الخاصة الأشد، فلا تُضاف سياسة التطبيق فوقها */
    private const OWN_POLICY_ROUTES = [
        // قالب البطاقة: default-src 'none' وnonce لسكربت ضبط المقاس (OfferCardService::cardPolicy)
        'offers.card.preview',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (Csp::mode() === 'off') {
            return $next($request);
        }

        $nonce = Vite::useCspNonce();
        $response = $next($request);

        if ($this->skip($request, $response) || !($header = Csp::header())) {
            return $response;
        }

        $response->headers->set($header, Csp::policy($nonce));
        $response->headers->set('Reporting-Endpoints', 'csp="'.url((string) config('csp.report_uri', '/csp-report')).'"');

        return $response;
    }

    private function skip(Request $request, Response $response): bool
    {
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return true;
        }
        if ($response->headers->has('Content-Security-Policy') || $response->headers->has('Content-Security-Policy-Report-Only')) {
            return true;
        }
        if (in_array($request->route()?->getName(), self::OWN_POLICY_ROUTES, true)) {
            return true;
        }

        // السياسة للمستندات: ردود JSON (Inertia والاستطلاع) لا تنفّذ شيئاً، فلا داعي لترويسة على كل استطلاع
        return !str_starts_with(strtolower((string) $response->headers->get('Content-Type', 'text/html')), 'text/html');
    }
}
