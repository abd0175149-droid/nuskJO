<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        return Inertia::render('Profile', [
            'title' => 'إعدادات الحساب',
            // رابط «الأمان والتحقق بخطوتين» (E27): حين يُفتح التسجيل، أو لمن سجّل قبل إغلاقه
            'security' => [
                'available' => \App\Services\Security\Mfa::enrollmentOpen() || $user->hasTwoFactor(),
                'enabled' => $user->hasTwoFactor(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
        ]);

        $user->update($validated);

        return back()->with('success', 'تم تحديث البيانات بنجاح');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => ['required', 'string', \App\Support\PasswordPolicy::rule(), 'confirmed'],
        ]);

        $user = auth()->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'كلمة المرور الحالية غير صحيحة']);
        }

        // كلمة مرور جديدة تُخرج بقية الأجهزة: جلساتها المخزّنة تُحذف، وauth.session يرفض أي جلسة أو
        // كوكي «تذكرني» مختوم بالبصمة القديمة. هذا الجهاز يُختم بالبصمة الجديدة في نهاية الطلب.
        $user->forceFill(['password' => Hash::make($request->password)])->save();
        Auth::logoutOtherDevices($request->password);   // يعيد ختم كوكي «تذكرني» لهذا الجهاز إن وُجد
        \App\Services\Security\SessionRevoker::revoke($user, keepCurrent: true, devices: false);
        \App\Models\AuditLog::log(\App\Enums\AuditAction::UserPasswordChange, 'User', $user->id, $user->name);

        return back()->with('success', 'تم تغيير كلمة المرور بنجاح');
    }
}
