<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // ويبهوك ميتا بلا وسيط مجموعة: لا جلسة ولا CSRF ولا Inertia (routes/webhooks.php)
            \Illuminate\Support\Facades\Route::group([], base_path('routes/webhooks.php'));
        },
    )
    // broadcasting/auth (E27): لا حدث لحظي لجلسة لم تجتز العامل الثاني. الدخول والتعطيل يُفحصان في routes/channels.php
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'mfa']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            // E26: CSP بـ nonce لكل صفحة HTML (تقارير افتراضياً، config/csp.php)
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        // رقم مرجعي لكل طلب (بعد TrustProxies): الويبهوك و/up والصفحات
        $middleware->append(\App\Http\Middleware\AssignRequestId::class);

        // الوكلاء الموثوقون من config/trustedproxy.php (TRUSTED_PROXIES) وقت الطلب، لا ثابتاً هنا:
        // env() في bootstrap لا يصل بعد config:cache

        $middleware->alias([
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'perm' => \App\Http\Middleware\RequirePermission::class,
            'authz.fence' => \App\Http\Middleware\EnsureRoutePermission::class,
            // E27: العامل الثاني لكل جلسة، والتحقق المعزّز على الأفعال الحساسة
            'mfa' => \App\Http\Middleware\EnsureSecondFactor::class,
            'stepup' => \App\Http\Middleware\RequireStepUp::class,
        ]);

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // /up يستدعي report() عند كل فشل (كل 30 ثانية من Docker)؛ OpsHealth يكتب سطراً لكل رمز في الساعة
        $exceptions->dontReport(\App\Exceptions\HealthCheckFailed::class);

        // فشل التحقق يحفظ المدخلات القديمة في الجلسة (جدول sessions): الأسرار لا تُحفظ هناك أبداً (E10)
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'wa_token', 'wa_app_secret', 'api_key', 'value', 'wa_verify_token', 'code', 'recovery_code']);

        // رفض صلاحية داخل صفحة Inertia: رسالة في الصفحة نفسها بدل صفحة 403 خام
        $exceptions->respond(function ($response, \Throwable $e, \Illuminate\Http\Request $request) {
            if ($response->getStatusCode() === 403 && $request->header('X-Inertia')) {
                return back()->with('error', 'ليست لديك صلاحية لهذا الإجراء');
            }

            return $response;
        });
    })->create();
