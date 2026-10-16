<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Security\SessionRevoker;
use App\Services\Security\TwoFactor;
use App\Support\AuditActor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * أمر طوارئ للقفل الكامل (E27): المدير الوحيد فقد هاتفه ورموز الاسترداد. يمسح التحقق بخطوتين لحساب واحد ويُخرجه
 * من كل أجهزته، فيدخل بكلمة المرور ويسجّل هاتفه من جديد. من داخل الحاوية وحدها، بتأكيد البريد مرة ثانية،
 * وبسبب يُدقَّق بفاعل system. لا يطبع أي سر.
 *
 *   docker exec -it -u www-data nuskjo-app php artisan security:mfa-reset owner@example.com --reason="فقد الهاتف"
 */
class SecurityMfaReset extends Command
{
    protected $signature = 'security:mfa-reset {email} {--reason= : السبب (10 أحرف على الأقل)}';

    protected $description = 'إعادة تعيين التحقق بخطوتين لحساب واحد (طوارئ: فقد الجهاز ورموز الاسترداد)';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->error('لا يوجد مستخدم بهذا البريد.');

            return self::FAILURE;
        }
        if (!$user->hasTwoFactor()) {
            $this->info('لا تحقق بخطوتين مفعّل لهذا الحساب. لا شيء يُعاد.');

            return self::SUCCESS;
        }

        $reason = trim((string) ($this->option('reason') ?: $this->ask('السبب (10 أحرف على الأقل)')));
        if (mb_strlen($reason) < 10) {
            $this->error('اكتب سبباً واضحاً (10 أحرف على الأقل).');

            return self::FAILURE;
        }
        if (trim((string) $this->ask("للتأكيد اكتب البريد مرة ثانية ({$user->name})")) !== $email) {
            $this->error('البريد لا يطابق. لم يتغير شيء.');

            return self::FAILURE;
        }

        AuditActor::as('system', 'artisan:'.$this->getName(), fn () => DB::transaction(function () use ($user, $reason) {
            TwoFactor::clear($user);
            AuditLog::log(AuditAction::AuthMfaReset, 'User', $user->id, $user->name, ['reason' => mb_substr($reason, 0, 200), 'by' => 'console']);
        }));
        SessionRevoker::revoke($user);
        rescue(fn () => NotificationService::send($user->id, '🔐 أُعيد تعيين التحقق بخطوتين', 'أُعيد تعيين التحقق بخطوتين لحسابك من الخادم — سجّله من جديد من صفحة «الأمان» بعد الدخول.', [
            'type' => 'security', 'icon' => '🔐', 'action_url' => '/profile/security',
        ]), null, false);

        $this->info("أُعيد تعيين التحقق بخطوتين لـ {$user->email}، وخرج من كل أجهزته. يدخل بكلمة المرور ثم يسجّل هاتفه من «الأمان».");
        $this->line('نسي كلمة المرور أيضاً؟ php artisan auth:restore-admin '.$user->email.' --reset-password');

        return self::SUCCESS;
    }
}
