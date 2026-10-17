<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\Security\Mfa;
use App\Services\Security\StepUp;
use App\Services\Security\TwoFactor;
use App\Support\Totp;

/** أدوات اختبار العامل الثاني (E27): تسجيل مباشر، والرمز الحالي، وجلسة اجتازت التحدّي أو التحقق المعزّز. */
trait EnrollsSecondFactor
{
    protected const TEST_TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /** @return list<string> رموز الاسترداد */
    protected function enroll(User $user, string $secret = self::TEST_TOTP_SECRET): array
    {
        return TwoFactor::enable($user, $secret, Totp::timestep(now()->getTimestamp()) - 10);
    }

    protected function codeNow(string $secret = self::TEST_TOTP_SECRET, int $offset = 0): string
    {
        return Totp::at($secret, Totp::timestep(now()->getTimestamp()) + $offset);
    }

    /** جلسة اجتازت التحدّي (ونافذة التحقق المعزّز مفتوحة إن طُلب) */
    protected function passedSession(User $user, bool $stepUp = true): array
    {
        return array_filter([
            Mfa::SESSION_KEY => $user->id,
            StepUp::SESSION_KEY => $stepUp ? now()->getTimestamp() : null,
        ]);
    }

    protected function staffMfaOn(): void
    {
        config(['features.env.staff_mfa' => true]);
    }
}
