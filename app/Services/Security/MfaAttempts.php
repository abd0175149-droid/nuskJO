<?php

namespace App\Services\Security;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * إخفاقات التحدّي والتحقق المعزّز (E27): عشرة رموز خاطئة خلال 15 دقيقة تُخرج المستخدم من الجلسة، مع صف تدقيق
 * وإشعار له وللمدراء. الحد في الدقيقة (throttle:mfa) منفصل عنه. العدّاد على مخزن limiter (file، لا SQLite).
 */
final class MfaAttempts
{
    public static function key(User $user): string
    {
        return 'mfa-fail:'.$user->id;
    }

    public static function clear(User $user): void
    {
        RateLimiter::clear(self::key($user));
    }

    /** يعدّ الإخفاق؛ true إن بلغ الحد وخرج المستخدم (المستدعي يعيد التوجيه إلى الدخول) */
    public static function failed(Request $request, User $user, AuditAction $action, string $method): bool
    {
        AuditLog::sampled($action, 'mfa:'.$user->id, 'User', $user->id, $user->name, ['method' => $method]);

        $key = self::key($user);
        RateLimiter::hit($key, (int) config('security.mfa.lockout_minutes', 15) * 60);
        if (RateLimiter::attempts($key) < (int) config('security.mfa.lockout_failures', 10)) {
            return false;
        }

        self::clear($user);
        AuditLog::safe(AuditAction::AuthMfaLockout, 'User', $user->id, $user->name, ['method' => $method]);
        rescue(function () use ($user) {
            NotificationService::send($user->id, '🔐 محاولات تحقق كثيرة', 'أُدخلت رموز تحقق خاطئة كثيرة لحسابك فخرج من الجلسة. إن لم تكن أنت فغيّر كلمة مرورك وأبلغ المدير.', [
                'type' => 'security', 'icon' => '🔐', 'action_url' => '/profile/security',
            ]);
            NotificationService::notifyAdmins('🔐 قفل التحقق بخطوتين', "محاولات تحقق خاطئة كثيرة لحساب {$user->name}، وخرج من الجلسة.", [
                'type' => 'security', 'icon' => '🔐', 'action_url' => '/users',
            ]);
        }, null, false);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return true;
    }
}
