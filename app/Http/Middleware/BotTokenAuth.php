<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * توثيق واجهة البوت.
 *
 * توكن ثابت تحمله منصّة AiBot في `secrets_enc` وتحقنه في ترويسة كلّ نداء.
 * والمقارنة **ثابتة الزمن** — `hash_equals` لا `===` — فمقارنةٌ عاديّة
 * تُسرّب طول البادئة المطابقة لمن يقيس زمن الردّ.
 */
class BotTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.bot.token');

        // غياب التوكن يعني **إغلاق الواجهة**، لا فتحها بلا تحقّق.
        if ($expected === '') {
            return response()->json(['ok' => false, 'error' => 'BOT_API_TOKEN غير مضبوط'], 503);
        }

        $sent = (string) $request->bearerToken();

        if ($sent === '' || ! hash_equals($expected, $sent)) {
            return response()->json(['ok' => false, 'error' => 'غير مصرّح'], 401);
        }

        return $next($request);
    }
}
