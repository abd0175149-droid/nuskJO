<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Security\MfaAttempts;
use App\Services\Security\StepUp;
use App\Services\Security\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * تأكيد الهوية قبل فعل حساس (E27): رمز تطبيق المصادقة لمن سجّله، وكلمة المرور لمن لا عامل له.
 * الصفحة لطلبات GET المحوَّلة (تعود إلى الوجهة بعدها)، وPOST JSON لنافذة التأكيد التي تعيد الطلب الأصلي.
 */
class ConfirmStepUpController extends Controller
{
    public function show(Request $request)
    {
        return Inertia::render('Auth/ConfirmStepUp', [
            'method' => $request->user()->hasSecondFactor() ? 'totp' : 'password',
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $totp = $user->hasSecondFactor();
        $field = $totp ? 'code' : 'password';
        $data = $request->validate([$field => 'required|string|max:'.($totp ? 20 : 255)], [
            "{$field}.required" => $totp ? 'اكتب الرمز من تطبيق المصادقة.' : 'اكتب كلمة المرور.',
        ]);

        $ok = $totp ? TwoFactor::verify($user, $data['code']) : Hash::check($data['password'], (string) $user->password);
        if (!$ok) {
            $lockedOut = MfaAttempts::failed($request, $user, AuditAction::AuthStepUpFailed, $totp ? 'totp' : 'password');
            if ($lockedOut) {
                if ($request->expectsJson() && !$request->header('X-Inertia')) {
                    return response()->json(['code' => 'MFA_LOCKOUT', 'message' => 'محاولات كثيرة: خرجت من الحساب.'], 401);
                }

                return redirect('/login')->withErrors(['email' => 'محاولات تأكيد كثيرة: خرجت من الحساب. أعد الدخول بعد قليل.']);
            }

            throw ValidationException::withMessages([$field => $totp ? 'الرمز غير صحيح أو مستعمل. اكتب الرمز الظاهر الآن.' : 'كلمة المرور غير صحيحة.']);
        }

        MfaAttempts::clear($user);
        StepUp::confirm($request);
        AuditLog::safe(AuditAction::AuthStepUp, 'User', $user->id, $user->name, ['method' => $totp ? 'totp' : 'password']);

        if ($request->expectsJson() && !$request->header('X-Inertia')) {
            return response()->json(['ok' => true, 'until' => StepUp::expiresAt($request)]);
        }

        return redirect()->intended(route('dashboard'));
    }
}
