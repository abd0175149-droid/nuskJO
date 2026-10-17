<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * الحساب المعطّل يخرج في طلبه التالي، لا عند انتهاء جلسته. يُفرض دائماً (لا يخضع لوضع log).
 * طلبات axios/JSON تتلقى 401، وزيارات الصفحات تُحوَّل إلى الدخول برسالة.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || $user->is_active) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() && !$request->header('X-Inertia')) {
            return response()->json(['message' => 'تم تعطيل حسابك'], 401);
        }

        return redirect('/login')->withErrors(['email' => 'تم تعطيل حسابك']);
    }
}
