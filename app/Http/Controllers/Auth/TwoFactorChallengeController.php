<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Security\Mfa;
use App\Services\Security\MfaAttempts;
use App\Services\Security\StepUp;
use App\Services\Security\TwoFactor;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * تحدّي العامل الثاني بعد كلمة المرور (E27): رمز تطبيق المصادقة أو رمز استرداد. داخل auth ومستثنى بالاسم من
 * الوسيط mfa (EnsureSecondFactor). النجاح يجدد الجلسة ويختمها ويفتح نافذة التحقق المعزّز الأولى.
 */
class TwoFactorChallengeController extends Controller
{
    public function show(Request $request)
    {
        if (!Mfa::challengePending($request->user())) {
            return redirect()->intended(route('dashboard'));
        }

        return Inertia::render('Auth/TwoFactorChallenge', [
            'name' => $request->user()->name,
        ]);
    }

    public function verify(Request $request)
    {
        $user = $request->user();
        if (!Mfa::challengePending($user)) {
            return redirect()->intended(route('dashboard'));
        }

        $data = $request->validate([
            'code' => 'nullable|string|max:20',
            'recovery_code' => 'nullable|string|max:40',
        ]);
        $recovery = filled($data['recovery_code'] ?? null);
        $field = $recovery ? 'recovery_code' : 'code';
        if (!filled($data[$field] ?? null)) {
            return back()->withErrors([$field => 'اكتب الرمز.']);
        }

        $ok = $recovery
            ? TwoFactor::useRecoveryCode($user, $data['recovery_code']) !== null
            : TwoFactor::verify($user, $data['code']);

        if (!$ok) {
            if (MfaAttempts::failed($request, $user, AuditAction::AuthMfaFailed, $recovery ? 'recovery' : 'totp')) {
                return redirect('/login')->withErrors(['email' => 'رموز خاطئة كثيرة: خرجت من الحساب. أعد الدخول بعد قليل، أو استعمل رمز استرداد.']);
            }

            return back()->withErrors([$field => $recovery
                ? 'رمز الاسترداد غير صحيح أو مستعمل.'
                : 'الرمز غير صحيح أو انتهت مدته. اكتب الرمز الظاهر الآن في التطبيق.']);
        }

        MfaAttempts::clear($user);
        $request->session()->regenerate();
        $request->session()->put(Mfa::SESSION_KEY, $user->id);
        StepUp::confirm($request);
        AuditLog::safe(AuditAction::AuthMfaPassed, 'User', $user->id, $user->name, ['method' => $recovery ? 'recovery' : 'totp']);

        // رمز الاسترداد: إشعار الجرس بالباقي يرسله TwoFactor::useRecoveryCode
        return redirect()->intended(route('dashboard'));
    }
}
