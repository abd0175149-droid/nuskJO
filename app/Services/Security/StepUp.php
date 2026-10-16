<?php

namespace App\Services\Security;

use App\Support\FeatureFlags;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * التحقق المعزّز (E27): فعل حساس يتطلب تأكيد الهوية خلال آخر ربع ساعة (STEPUP_TTL). المفتاح نفسه الذي يستعمله
 * Laravel لتأكيد كلمة المرور، فتتوافق الآليتان. اجتياز تحدّي الدخول أو الدخول بكلمة المرور يفتح النافذة الأولى.
 */
final class StepUp
{
    public const SESSION_KEY = 'auth.password_confirmed_at';

    /** العلم step_up: الأفعال الحساسة في routes/web.php. مسارات إدارة العامل الثاني نفسها تطلبه دائماً (stepup:always) */
    public static function enforced(): bool
    {
        return FeatureFlags::available('step_up') && FeatureFlags::on('step_up');
    }

    public static function ttl(): int
    {
        return max(60, (int) config('security.stepup.ttl', 900));
    }

    public static function fresh(Request $request, ?int $ttl = null): bool
    {
        if (!$request->hasSession()) {
            return false;
        }
        $at = (int) $request->session()->get(self::SESSION_KEY, 0);

        return $at > 0 && now()->getTimestamp() - $at <= ($ttl ?? self::ttl());
    }

    public static function confirm(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
    }

    /**
     * ردّ «أكّد هويتك» إن انتهت النافذة، أو null: JSON يتلقى 423 STEP_UP_REQUIRED (تفتح الواجهة نافذة التأكيد وتعيد
     * الطلب)، وGET يُحوَّل إلى صفحة التأكيد ثم يعود، وزيارة Inertia غير GET تعود للخلف بعلامة stepup_required
     * (لا intended: مسار POST يعطي 405). $always يتجاوز العلم.
     */
    public static function challengeResponse(Request $request, bool $always = false, ?int $ttl = null): ?Response
    {
        if (!$request->user() || (!$always && !self::enforced()) || self::fresh($request, $ttl)) {
            return null;
        }
        if ($request->expectsJson() && !$request->header('X-Inertia')) {
            return response()->json(['code' => 'STEP_UP_REQUIRED', 'message' => 'أكّد هويتك لإتمام هذا الإجراء'], 423);
        }
        if ($request->isMethod('GET')) {
            return redirect()->guest(route('security.confirm'));
        }

        return back()->with('stepup_required', true);
    }

    /** للأفعال التي تُقرَّر داخل المتحكم (استئناف الإرسال بعد الإيقاف الطارئ؛ الإيقاف نفسه لا يُعاق) */
    public static function ensure(Request $request, bool $always = false): void
    {
        if ($response = self::challengeResponse($request, $always)) {
            throw new HttpResponseException($response);
        }
    }

    /** متى تنتهي النافذة الحالية (unix)، أو null */
    public static function expiresAt(Request $request): ?int
    {
        return self::fresh($request) ? (int) $request->session()->get(self::SESSION_KEY) + self::ttl() : null;
    }
}
