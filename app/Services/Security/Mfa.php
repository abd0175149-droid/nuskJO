<?php

namespace App\Services\Security;

use App\Models\User;
use App\Support\FeatureFlags;
use Illuminate\Support\Carbon;

/**
 * سياسة العامل الثاني (E27) في مكان واحد يقرؤه الوسيط والمتحكمات والواجهة:
 *  - التسجيل الطوعي خلف العلم staff_mfa.
 *  - التحدّي عند الدخول لكل من سجّل، ما لم يُعطَّل بمنفذ الطوارئ MFA_CHALLENGE=off.
 *  - الفرض (MFA_ENFORCE) لأصحاب الصلاحيات الحساسة: off ثم warn ثم on بعد MFA_ENFORCE_FROM، ولا فرض والعلم مطفأ.
 */
final class Mfa
{
    /** مفتاح الجلسة: معرّف من اجتاز العامل الثاني في هذه الجلسة. جلسة جديدة (ومنها كوكي «تذكرني») بلا هذا المفتاح */
    public const SESSION_KEY = 'auth.mfa_uid';

    /** السر المعلّق أثناء التسجيل، مشفّراً في الجلسة لا في users */
    public const PENDING_KEY = 'mfa.pending_secret';

    public static function enrollmentOpen(): bool
    {
        return FeatureFlags::available('staff_mfa') && FeatureFlags::on('staff_mfa');
    }

    public static function challengeEnabled(): bool
    {
        return strtolower((string) config('security.mfa.challenge', 'on')) !== 'off';
    }

    public static function enforceFrom(): ?Carbon
    {
        $from = config('security.mfa.enforce_from');
        if (!$from) {
            return null;
        }

        return rescue(fn () => Carbon::parse((string) $from, 'Asia/Amman'), null, false);
    }

    /** off | warn | on لهذا المستخدم الآن */
    public static function enforcementFor(User $user): string
    {
        $mode = strtolower((string) config('security.mfa.enforce', 'off'));
        if (!in_array($mode, ['warn', 'on'], true) || !self::enrollmentOpen() || $user->hasSecondFactor() || !$user->requiresMfa()) {
            return 'off';
        }
        if ($mode === 'on') {
            $from = self::enforceFrom();

            return $from === null || now()->greaterThanOrEqualTo($from) ? 'on' : 'warn';
        }

        return 'warn';
    }

    /** هل اجتاز العامل الثاني في هذه الجلسة؟ */
    public static function passed(User $user): bool
    {
        $session = request()->hasSession() ? request()->session() : null;

        return $session !== null && (int) $session->get(self::SESSION_KEY) === (int) $user->id;
    }

    /** هل تحتاج هذه الجلسة تحدّياً قبل أي شيء؟ */
    public static function challengePending(User $user): bool
    {
        return self::challengeEnabled() && $user->hasSecondFactor() && !self::passed($user);
    }

    /** للواجهة (HandleInertiaRequests): حالة الفرض وتاريخه، بلا أي سر. لا نداء للقاعدة والفرض off */
    public static function shared(?User $user): array
    {
        $enforcement = $user ? self::enforcementFor($user) : 'off';

        return [
            'mfa_needed' => $enforcement !== 'off',
            'mfa_enforce_from' => $enforcement === 'warn' ? self::enforceFrom()?->toDateString() : null,
        ];
    }
}
