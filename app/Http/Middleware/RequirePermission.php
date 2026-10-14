<?php

namespace App\Http\Middleware;

use App\Support\Authz;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * perm:a,b — يكفي أن يملك المستخدم إحداها. في وضع log يُسجَّل الرفض ويُسمح بالطلب،
 * إلا الصلاحيات في authz.always_enforce فتُرفض دائماً.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();
        foreach ($abilities as $ability) {
            if ($user?->can($ability)) {
                return $next($request);
            }
        }

        $enforced = !Authz::logOnly() || array_intersect($abilities, config('authz.always_enforce', []));
        Authz::record($request, $enforced ? 'authz.denied' : 'authz.would_deny', ['perm' => implode('|', $abilities)]);

        abort_if($enforced, 403);

        return $next($request);
    }
}
