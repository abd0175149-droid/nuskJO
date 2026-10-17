<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors([
                'email' => 'بيانات الدخول غير صحيحة.',
            ])->onlyInput('email');
        }

        $user = Auth::user();

        if (!$user->is_active) {
            Auth::logout();
            \App\Models\AuditLog::safe(\App\Enums\AuditAction::AuthInactiveBlocked, 'User', $user->id, $user->name);
            return back()->withErrors([
                'email' => 'تم تعطيل حسابك. تواصل مع المدير.',
            ]);
        }

        // آخر عنوان فقط، ويُعرض لصاحبه ولمن يملك تعديل المستخدمين (E27)
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $request->session()->regenerate();
        $request->session()->forget(\App\Services\Security\Mfa::SESSION_KEY);

        // له عامل ثانٍ: لا شيء قبل التحدّي (الوسيط mfa يحرس كل مسار، والوجهة الأصلية تبقى في الجلسة)
        if (\App\Services\Security\Mfa::challengePending($user)) {
            return redirect()->route('two-factor.challenge');
        }
        // كلمة المرور الآن تأكيد هوية: نافذة التحقق المعزّز الأولى
        \App\Services\Security\StepUp::confirm($request);

        return redirect()->intended('/');
    }

    public function logout(Request $request)
    {
        // توكن إشعارات هذا الجهاز: لا تصل إشعارات المستخدم بعد خروجه
        if ($token = $request->input('push_token')) {
            \App\Models\FcmToken::where('token', $token)->where('user_id', Auth::id())->delete();
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
