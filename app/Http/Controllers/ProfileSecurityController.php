<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Security\Mfa;
use App\Services\Security\SessionRevoker;
use App\Services\Security\StepUp;
use App\Services\Security\TwoFactor;
use App\Support\Totp;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * صفحة «الأمان والتحقق بخطوتين» (E27): كل مستخدم على حسابه وحده (authz.allowlist).
 * السر يُولَّد في الجلسة مشفّراً حتى يؤكَّد أول رمز، ثم يُحفظ في users. ورموز الاسترداد تُعاد نصاً مرة واحدة في JSON
 * بلا تخزين (Cache-Control: no-store)، لا في props ولا flash. التفعيل والتعطيل وتجديد الرموز خلف stepup:always.
 */
class ProfileSecurityController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $block = $this->disableBlockedReason($user);

        return Inertia::render('Profile/Security', [
            'title' => 'الأمان والتحقق بخطوتين',
            'security' => [
                'enabled' => $user->hasTwoFactor(),
                'confirmed_at' => $user->two_factor_confirmed_at?->timezone('Asia/Amman')->format('Y-m-d'),
                'recovery_remaining' => $user->hasTwoFactor() ? TwoFactor::remainingRecoveryCodes($user) : 0,
                'required' => $user->requiresMfa(),
                'enforcement' => Mfa::enforcementFor($user),
                'enforce_from' => Mfa::enforceFrom()?->toDateString(),
                'enrollment_open' => Mfa::enrollmentOpen(),
                'disable_blocked' => $block,
                'last_login_at' => $user->last_login_at?->timezone('Asia/Amman')->format('Y-m-d H:i'),
                // عنوان صاحب الحساب نفسه: يساعده على ملاحظة دخول لم يقم به
                'last_login_ip' => $user->last_login_ip,
            ],
        ]);
    }

    /** POST profile/two-factor: سر جديد معلّق في الجلسة، وQR يُرسم هنا. لا كتابة في users قبل التأكيد */
    public function enable(Request $request): JsonResponse
    {
        abort_unless(Mfa::enrollmentOpen(), 404);
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'التحقق بخطوتين مفعّل أصلاً. لتغيير الهاتف: عطّله ثم فعّله من جديد.']);
        }

        $secret = TwoFactor::newSecret();
        $request->session()->put(Mfa::PENDING_KEY, Crypt::encryptString($secret));

        return $this->noStore([
            'qr' => 'data:image/svg+xml;base64,'.base64_encode(TwoFactor::qrSvg($user, $secret)),
            'secret' => Totp::grouped($secret),
        ]);
    }

    /** POST profile/two-factor/confirm: أول رمز صحيح يحفظ العامل ويعيد رموز الاسترداد مرة واحدة */
    public function confirm(Request $request): JsonResponse
    {
        abort_unless(Mfa::enrollmentOpen(), 404);
        $data = $request->validate(['code' => 'required|string|max:20'], ['code.required' => 'اكتب الرمز من تطبيق المصادقة.']);
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'التحقق بخطوتين مفعّل أصلاً.']);
        }

        try {
            $secret = Crypt::decryptString((string) $request->session()->get(Mfa::PENDING_KEY));
        } catch (DecryptException) {
            throw ValidationException::withMessages(['code' => 'انتهت خطوة التفعيل. ابدأ من جديد.']);
        }
        $step = Totp::match($secret, $data['code'], time: now()->getTimestamp());
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'الرمز غير صحيح. تأكد من ضبط وقت الهاتف تلقائياً واكتب الرمز الظاهر الآن.']);
        }

        $codes = TwoFactor::enable($user, $secret, $step);
        $request->session()->forget(Mfa::PENDING_KEY);
        // هذه الجلسة اجتازت العامل الثاني للتو، وجلسات الأجهزة الأخرى ستُتحدّى في طلبها التالي
        $request->session()->put(Mfa::SESSION_KEY, $user->id);
        StepUp::confirm($request);

        return $this->noStore(['recovery_codes' => $codes, 'confirmed_at' => now()->timezone('Asia/Amman')->format('Y-m-d')]);
    }

    /** POST profile/two-factor/recovery-codes: عشرة رموز جديدة تُبطل القديمة */
    public function regenerateCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'فعّل التحقق بخطوتين أولاً.']);
        }

        return $this->noStore(['recovery_codes' => TwoFactor::regenerateRecoveryCodes($user)]);
    }

    /** DELETE profile/two-factor: يمسح العامل ويُخرج بقية الأجهزة */
    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'التحقق بخطوتين غير مفعّل.']);
        }
        if ($reason = $this->disableBlockedReason($user)) {
            throw ValidationException::withMessages(['code' => $reason]);
        }

        DB::transaction(function () use ($user) {
            TwoFactor::clear($user);
            AuditLog::log(AuditAction::AuthMfaDisabled, 'User', $user->id, $user->name, ['by' => 'self']);
        });
        SessionRevoker::revoke($user, keepCurrent: true, devices: false);
        $request->session()->forget(Mfa::SESSION_KEY);

        return response()->json(['ok' => true]);
    }

    /** لماذا لا يُعطَّل الآن، أو null */
    private function disableBlockedReason(User $user): ?string
    {
        if (!$user->hasTwoFactor()) {
            return null;
        }
        if (strtolower((string) config('security.mfa.enforce')) === 'on' && Mfa::enrollmentOpen() && $user->requiresMfa()) {
            return 'صلاحياتك تتطلب التحقق بخطوتين فلا يُعطَّل. إن غيّرت هاتفك فاطلب من المدير إعادة تعيينه ثم سجّل الجديد.';
        }
        if (TwoFactor::isLastAdminWithFactor($user)) {
            return 'أنت آخر مدير نشط بتحقق بخطوتين، فلا يُعطَّل من هنا. فعّله لمدير آخر أولاً.';
        }

        return null;
    }

    private function noStore(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
