<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** أدوات مشتركة لحارس الصلاحيات (config/authz.php). */
class Authz
{
    public static function logOnly(): bool
    {
        return config('authz.mode') === 'log';
    }

    /** اسم المسار، أو «METHOD uri» للمسارات بلا اسم. */
    public static function routeKey(Request $request): string
    {
        $route = $request->route();

        return $route?->getName() ?? $request->method().' '.($route?->uri() ?? $request->path());
    }

    /**
     * سجل تدقيق مقلَّص: مرة في الساعة لكل مستخدم ومسار وفعل (استطلاع الصندوق كل 15 ثانية لا يُغرق السجل).
     * لا جسم طلب ولا بيانات عميل.
     */
    public static function record(Request $request, string $action, array $extra): void
    {
        $key = self::routeKey($request);
        $uid = $request->user()?->id ?? 0;

        if (!Cache::add("authz:{$action}:{$uid}:".md5($key).':'.now()->format('YmdH'), true, 3600)) {
            return;
        }

        try {
            AuditLog::log($action, 'route', 0, $key, $extra + [
                'method' => $request->method(),
                'role' => $request->user()?->role?->slug,
                'mode' => config('authz.mode'),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
