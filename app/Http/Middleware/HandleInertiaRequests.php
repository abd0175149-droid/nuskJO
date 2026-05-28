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
            ],
            'unreadNotifications' => $user ? (int) \App\Models\Notification::where('user_id', $user->id)->where('is_read', false)->count() : 0,
        ]);
    }
}
