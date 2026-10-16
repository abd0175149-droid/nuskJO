<?php

namespace App\Http\Middleware;

use App\Services\Security\StepUp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * التحقق المعزّز على الأفعال الحساسة (E27)، بعد صلاحية المسار لا بدلها:
 *   stepup          يُفرض حين يكون العلم step_up مفعّلاً. يُلحق بمسارات config('security.stepup.routes') آخر routes/web.php.
 *   stepup:always   يُفرض دائماً (إدارة العامل الثاني نفسه: التفعيل والتعطيل وتجديد الرموز وإعادة التعيين).
 *   stepup:600      مدة نافذة مختلفة بالثواني.
 * الرد حين تنتهي النافذة في StepUp::challengeResponse.
 */
class RequireStepUp
{
    public function handle(Request $request, Closure $next, string ...$options): Response
    {
        $ttl = null;
        foreach ($options as $option) {
            if (ctype_digit($option)) {
                $ttl = max(60, (int) $option);
            }
        }

        return StepUp::challengeResponse($request, in_array('always', $options, true), $ttl) ?? $next($request);
    }
}
