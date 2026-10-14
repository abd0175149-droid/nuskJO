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
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // رقم مرجعي لكل طلب (بعد TrustProxies): الويبهوك و/up والصفحات
        $middleware->append(\App\Http\Middleware\AssignRequestId::class);

        // Trust Cloudflare proxy headers
        $middleware->trustProxies(at: '*');

        // ويبهوك واتساب: لا CSRF (ميتا لا ترسل رمزاً) — الحماية بتوقيع HMAC داخل المتحكّم
        $middleware->validateCsrfTokens(except: [
            'api/whatsapp/webhook',
        ]);

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
