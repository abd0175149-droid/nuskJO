<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // ويبهوك ميتا بلا وسيط مجموعة: لا جلسة ولا CSRF ولا Inertia (routes/webhooks.php)
            \Illuminate\Support\Facades\Route::group([], base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // رقم مرجعي لكل طلب (بعد TrustProxies): الويبهوك و/up والصفحات
        $middleware->append(\App\Http\Middleware\AssignRequestId::class);

        // الوكلاء الموثوقون من config/trustedproxy.php (TRUSTED_PROXIES) وقت الطلب، لا ثابتاً هنا:
        // env() في bootstrap لا يصل بعد config:cache

        $middleware->alias([
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'perm' => \App\Http\Middleware\RequirePermission::class,
            'authz.fence' => \App\Http\Middleware\EnsureRoutePermission::class,
        ]);

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // رفض صلاحية داخل صفحة Inertia: رسالة في الصفحة نفسها بدل صفحة 403 خام
        $exceptions->respond(function ($response, \Throwable $e, \Illuminate\Http\Request $request) {
            if ($response->getStatusCode() === 403 && $request->header('X-Inertia')) {
                return back()->with('error', 'ليست لديك صلاحية لهذا الإجراء');
            }

            return $response;
        });
    })->create();
