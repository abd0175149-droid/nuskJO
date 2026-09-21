<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // Trust Cloudflare proxy headers
        $middleware->trustProxies(at: '*');

        // ويبهوك واتساب: لا CSRF (ميتا لا ترسل رمزاً) — الحماية بتوقيع HMAC داخل المتحكّم
        $middleware->validateCsrfTokens(except: [
            'api/whatsapp/webhook',
        ]);

        // حارس واجهة البوت — توكن ثابت تحمله منصّة AiBot
        $middleware->alias([
            'bot.token' => \App\Http\Middleware\BotTokenAuth::class,
        ]);

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
