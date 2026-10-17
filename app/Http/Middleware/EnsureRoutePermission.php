<?php

namespace App\Http\Middleware;

use App\Support\Authz;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * «مغلق افتراضياً»: كل مسار في المجموعة المحمية يعلن صلاحيته (perm: أو can:) أو يكون في authz.allowlist.
 * مسار جديد نُسي إعلانه يُرفض (أو يُسجَّل authz.unmapped في وضع log) بدل أن يكون مفتوحاً للجميع.
 */
class EnsureRoutePermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $declared = collect($route?->gatherMiddleware() ?? [])
            ->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'perm:') || str_starts_with($m, 'can:')));

        if ($declared || in_array(Authz::routeKey($request), config('authz.allowlist', []), true)) {
            return $next($request);
        }

        Authz::record($request, \App\Enums\AuditAction::AuthzUnmapped, []);
        abort_unless(Authz::logOnly(), 403);

        return $next($request);
    }
}
