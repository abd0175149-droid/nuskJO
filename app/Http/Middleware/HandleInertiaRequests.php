<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'locale' => $user->locale ?? 'ar',
                    'theme' => $user->theme ?? 'light',
                    'role' => $user->role ? [
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                        'slug' => $user->role->slug,
                    ] : null,
                ] : null,
                'isAdmin' => $user?->isAdmin() ?? false,
                'permissions' => $user?->role?->permissions?->pluck('slug')->toArray() ?? [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // E27: فعل حساس رُدّ لانتهاء نافذة التحقق المعزّز؛ نافذة التأكيد تعيده بعد النجاح
                'stepup_required' => fn () => (bool) $request->session()->get('stepup_required'),
            ],
            // E27: شريط «فعّل التحقق بخطوتين قبل…» وطريقة تأكيد الهوية (لا سر هنا). مفتاح mfa لا security: صفحتا
            // الملف الشخصي والأمان لهما prop باسم security يغلب المشترك
            'mfa' => fn () => \App\Services\Security\Mfa::shared($user),
            // مزايا خلف أعلام: القائمة تُخفي ما لم يُفعَّل بعد
            'features' => [
                'audit_page' => (bool) config('audit.page'),
                // E31: رابط «المحظورون» لمن يملك whatsapp.block والعلم مفعّل (القراءة لهؤلاء وحدهم)
                'wa_block' => $user?->can('whatsapp.block') ? rescue(fn () => \App\Support\FeatureFlags::on('wa_block'), false, false) : false,
            ],
            'unreadNotifications' => $user ? (int) \App\Models\Notification::where('user_id', $user->id)->where('is_read', false)->count() : 0,
        ]);
    }
}
