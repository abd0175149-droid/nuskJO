<?php

namespace App\Services\Security;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\QrCode;
use App\Support\Totp;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * العامل الثاني بتطبيق مصادقة (E27): توليد السر ورسم QR على خادمنا، والتحقق بنافذة ±30 ثانية مع منع إعادة
 * الاستعمال، وعشرة رموز استرداد تُخزَّن مجزّأة بـ bcrypt وتُعرض نصاً مرة واحدة. لا سر ولا رمز في أي سجل أو تدقيق.
 */
final class TwoFactor
{
    public const RECOVERY_COUNT = 10;

    /** base32 بلا 0/O و1/I: 32 رمزاً، فعشرة أحرف ≈ 50 بت لكل رمز */
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function newSecret(): string
    {
        return Totp::newSecret();
    }

    /** SVG لرابط otpauth، يُعاد نصاً ولا يُكتب في ملف أو سجل */
    public static function qrSvg(User $user, #[\SensitiveParameter] string $secret): string
    {
        return QrCode::svg(Totp::uri($secret, (string) config('security.mfa.issuer'), (string) $user->email));
    }

    /** السر المحفوظ، أو null إن لم يُسجَّل أو تعذّر فكّه (مفتاح التطبيق دُوِّر بلا APP_PREVIOUS_KEYS) */
    public static function secretOf(User $user): ?string
    {
        try {
            $secret = $user->two_factor_secret;
        } catch (DecryptException) {
            Log::error('mfa.secret_unreadable', ['user' => $user->id]);

            return null;
        }

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * رمز التطبيق للمستخدم: يُقبل مرة واحدة. الخطوة المقبولة تُحفظ بشرط أن تكون أحدث من المحفوظة في عبارة التحديث
     * نفسها، فلا يمرّ الرمز نفسه في طلبين متزامنين.
     */
    public static function verify(User $user, string $code): bool
    {
        $secret = self::secretOf($user);
        if ($secret === null || !$user->hasTwoFactor()) {
            return false;
        }
        $last = DB::table('users')->where('id', $user->id)->value('two_factor_last_step');
        $step = Totp::match($secret, $code, $last === null ? null : (int) $last, time: now()->getTimestamp());
        if ($step === null) {
            return false;
        }

        $claimed = DB::table('users')->where('id', $user->id)
            ->where(fn ($q) => $q->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $step))
            ->update(['two_factor_last_step' => $step]);
        if ($claimed === 0) {
            return false;
        }
        $user->forceFill(['two_factor_last_step' => $step])->syncOriginalAttribute('two_factor_last_step');

        return true;
    }

    /** @return list<string> عشرة رموز نصية بصيغة XXXXX-XXXXX، تُعرض مرة واحدة */
    public static function newRecoveryCodes(): array
    {
        $codes = [];
        while (count($codes) < self::RECOVERY_COUNT) {
            $raw = '';
            for ($i = 0; $i < 10; $i++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $codes[$raw] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return array_values($codes);
    }

    /** @param list<string> $codes @return list<string> تجزئة bcrypt لكل رمز (الصيغة الموحّدة بلا شرطة) */
    public static function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn (string $c) => Hash::make((string) self::normalizeRecovery($c)), $codes);
    }

    public static function normalizeRecovery(string $code): ?string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $code) ?? '');

        return strlen($code) === 10 && strspn($code, self::RECOVERY_ALPHABET) === 10 ? $code : null;
    }

    public static function remainingRecoveryCodes(User $user): int
    {
        try {
            return count((array) ($user->two_factor_recovery_codes ?? []));
        } catch (DecryptException) {
            return 0;
        }
    }

    /**
     * يستهلك رمز استرداد واحداً ذرياً (قفل الصف داخل معاملة)، ويدقّقه، ويُشعر صاحبه بالباقي.
     * يعيد عدد الرموز الباقية، أو null إن لم يطابق.
     */
    public static function useRecoveryCode(User $user, string $code): ?int
    {
        $normalized = self::normalizeRecovery($code);
        if ($normalized === null || !$user->hasTwoFactor()) {
            return null;
        }

        $remaining = DB::transaction(function () use ($user, $normalized) {
            $fresh = User::whereKey($user->id)->lockForUpdate()->first();
            try {
                $hashes = array_values((array) ($fresh?->two_factor_recovery_codes ?? []));
            } catch (DecryptException) {
                return null;
            }
            foreach ($hashes as $i => $hash) {
                if (is_string($hash) && Hash::check($normalized, $hash)) {
                    unset($hashes[$i]);
                    $fresh->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();
                    AuditLog::log(AuditAction::AuthMfaRecoveryUsed, 'User', $user->id, $user->name, ['remaining' => count($hashes)]);

                    return count($hashes);
                }
            }

            return null;
        });

        if ($remaining !== null) {
            $user->refresh();
            rescue(fn () => NotificationService::send($user->id, '🔐 استُعمل رمز استرداد', 'استُعمل رمز استرداد لحسابك — بقي '.$remaining.'.'
                .($remaining <= 3 ? ' جدّد الرموز من صفحة «الأمان».' : '').' إن لم تكن أنت فأبلغ المدير فوراً.', [
                    'type' => 'security', 'icon' => '🔐', 'action_url' => '/profile/security',
                ]), null, false);
        }

        return $remaining;
    }

    /**
     * يحفظ العامل الثاني بعد تأكيد أول رمز، ويعيد رموز الاسترداد نصاً (مرة واحدة). $step خطوة الرمز المؤكِّد،
     * فلا يُقبل هو نفسه في التحدّي التالي.
     *
     * @return list<string>
     */
    public static function enable(User $user, #[\SensitiveParameter] string $secret, int $step): array
    {
        $codes = self::newRecoveryCodes();
        DB::transaction(function () use ($user, $secret, $step, $codes) {
            $user->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_recovery_codes' => self::hashRecoveryCodes($codes),
                'two_factor_confirmed_at' => now(),
                'two_factor_last_step' => $step,
            ])->save();
            AuditLog::log(AuditAction::AuthMfaEnrolled, 'User', $user->id, $user->name, ['method' => 'totp']);
        });

        return $codes;
    }

    /** @return list<string> رموز جديدة تُبطل القديمة كلها */
    public static function regenerateRecoveryCodes(User $user): array
    {
        $codes = self::newRecoveryCodes();
        DB::transaction(function () use ($user, $codes) {
            $user->forceFill(['two_factor_recovery_codes' => self::hashRecoveryCodes($codes)])->save();
            AuditLog::log(AuditAction::AuthMfaCodesRegenerated, 'User', $user->id, $user->name, ['count' => count($codes)]);
        });

        return $codes;
    }

    /** يمسح العامل الثاني كله. السبب والفاعل يكتبهما المستدعي في صف التدقيق داخل المعاملة نفسها */
    public static function clear(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    /** هل يبقى مدير نشط آخر بعامل ثانٍ إن أُزيل عامل هذا المستخدم؟ (حارس «آخر مدير بعامل ثانٍ») */
    public static function isLastAdminWithFactor(User $user): bool
    {
        if (!$user->isAdmin() || !$user->hasTwoFactor() || !$user->is_active) {
            return false;
        }
        $adminRole = AdminInvariant::adminRoleId();

        return !User::where('id', '!=', $user->id)->where('role_id', $adminRole)->where('is_active', true)
            ->whereNotNull('two_factor_confirmed_at')->exists();
    }
}
