<?php

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Services\Security\Mfa;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * العامل الثاني لكل جلسة (E27)، بعد active وقبل authz.fence:
 *  1. من سجّل عاملاً ثانياً ولم يجتزه في هذه الجلسة (ومنها جلسة كوكي «تذكرني») لا يصل إلا إلى التحدّي والخروج:
 *     JSON يتلقى 401 MFA_REQUIRED، والصفحات تُحوَّل إلى التحدّي (مع حفظ الوجهة لطلبات GET وحدها).
 *  2. صاحب صلاحية حساسة بلا عامل ثانٍ والفرض on: لا يصل إلا إلى صفحة «الأمان» والخروج (403 MFA_ENROLL_REQUIRED لـ JSON).
 *  3. MFA_CHALLENGE=off منفذ طوارئ: يمر كل شيء (التحدّي والفرض)، ويُدقَّق مرة في الساعة لكل مستخدم له عامل.
 * الاستثناء بأسماء المسارات لا ببادئتها.
 */
class EnsureSecondFactor
{
    /** مسارات تصل إليها الجلسة قبل اجتياز التحدّي */
    public const CHALLENGE_ROUTES = ['two-factor.challenge', 'two-factor.verify', 'logout'];

    /** مسارات يصل إليها صاحب الصلاحية الحساسة حين يُفرض عليه التسجيل */
    public const ENROLL_ROUTES = [
        'profile.security', 'profile.two-factor.enable', 'profile.two-factor.confirm',
        'security.confirm', 'security.confirm.store', 'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }
        $route = $request->route()?->getName();

        // منفذ الطوارئ يعطّل التحدّي والفرض معاً؛ أثره في التدقيق مرة في الساعة لكل من كان سيُتحدّى
        if (!Mfa::challengeEnabled()) {
            if ($user->hasSecondFactor() && Cache::add('mfa:bypass:'.$user->id.':'.now()->format('YmdH'), true, 3600)) {
                AuditLog::safe(AuditAction::AuthMfaBypassed, 'User', $user->id, $user->name, ['reason' => 'MFA_CHALLENGE=off']);
            }

            return $next($request);
        }

        if ($user->hasSecondFactor()) {
            if (Mfa::passed($user) || in_array($route, self::CHALLENGE_ROUTES, true)) {
                return $next($request);
            }
            if ($this->wantsJson($request)) {
                return response()->json(['code' => 'MFA_REQUIRED', 'message' => 'أكمل التحقق بخطوتين أولاً'], 401);
            }

            return $request->isMethod('GET')
                ? redirect()->guest(route('two-factor.challenge'))
                : redirect()->route('two-factor.challenge');
        }

        if (Mfa::enforcementFor($user) === 'on' && !in_array($route, self::ENROLL_ROUTES, true)) {
            if ($this->wantsJson($request)) {
                return response()->json(['code' => 'MFA_ENROLL_REQUIRED', 'message' => 'صلاحياتك تتطلب التحقق بخطوتين قبل المتابعة'], 403);
            }

            return redirect()->route('profile.security')->with('error', 'صلاحياتك تتطلب التحقق بخطوتين قبل المتابعة');
        }

        return $next($request);
    }

    /** axios وJSON، لا زيارات Inertia (لها تحويل) */
    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() && !$request->header('X-Inertia');
    }
}
