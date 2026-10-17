<?php

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Support\AuditActor;
use App\Support\AuditRedactor;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;

/**
 * أحداث الدخول في سجل التدقيق (E09). مشترك يُسجَّل صراحة في AppServiceProvider: دوالّه لا تبدأ بـ handle،
 * فلا يلتقطها اكتشاف الأحداث مرة ثانية. البريد مقنّع دائماً، وكلمة المرور لا تُقرأ أصلاً.
 */
class AuditAuthEvents
{
    public function failed(Failed $event): void
    {
        $email = Str::lower((string) ($event->credentials['email'] ?? ''));
        AuditLog::sampled(AuditAction::AuthLoginFailed, 'login:'.$email.'|'.request()->ip(), 'User',
            $event->user?->getAuthIdentifier(), AuditRedactor::maskEmail($email));
    }

    public function lockout(Lockout $event): void
    {
        $email = Str::lower((string) $event->request->input('email'));
        AuditLog::sampled(AuditAction::AuthLockout, 'lockout:'.$email.'|'.$event->request->ip(), 'User', null, AuditRedactor::maskEmail($email));
    }

    public function login(Login $event): void
    {
        // المعطّل يُخرَج فوراً ويُسجَّل له auth.inactive_blocked في LoginController، لا دخول
        if ($event->user->is_active ?? true) {
            AuditActor::as('user', null, fn () => AuditLog::safe(AuditAction::AuthLogin, 'User', $event->user->getAuthIdentifier(), $event->user->name ?? null),
                $event->user->getAuthIdentifier());
        }
    }

    public function logout(Logout $event): void
    {
        if ($event->user && ($event->user->is_active ?? true)) {
            AuditActor::as('user', null, fn () => AuditLog::safe(AuditAction::AuthLogout, 'User', $event->user->getAuthIdentifier(), $event->user->name ?? null),
                $event->user->getAuthIdentifier());
        }
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Failed::class => 'failed',
            Lockout::class => 'lockout',
            Login::class => 'login',
            Logout::class => 'logout',
        ];
    }
}
