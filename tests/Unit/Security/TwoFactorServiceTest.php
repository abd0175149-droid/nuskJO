<?php

namespace Tests\Unit\Security;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Services\Security\TwoFactor;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** خدمة العامل الثاني (E27): الرمز يُقبل مرة، ورموز الاسترداد مجزّأة تُستهلك مرة واحدة، والسر مشفّر في القاعدة. */
class TwoFactorServiceTest extends TestCase
{
    use RefreshDatabase;

    private function enrolled(string $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'): User
    {
        $user = User::factory()->create();
        TwoFactor::enable($user, $secret, Totp::timestep(now()->getTimestamp()) - 5);

        return $user->fresh();
    }

    public function test_a_code_for_the_current_step_is_accepted_once_and_its_step_saved(): void
    {
        $this->travelTo(now()->setTimestamp(1_700_000_000));
        $user = $this->enrolled();
        $secret = TwoFactor::secretOf($user);
        $step = Totp::timestep(now()->getTimestamp());

        $this->assertTrue(TwoFactor::verify($user, Totp::at($secret, $step)));
        $this->assertSame($step, (int) $user->fresh()->two_factor_last_step);
        $this->assertFalse(TwoFactor::verify($user->fresh(), Totp::at($secret, $step)), 'الرمز نفسه لا يمر مرتين');
    }

    public function test_the_previous_step_is_accepted_once_and_two_steps_back_is_refused(): void
    {
        $this->travelTo(now()->setTimestamp(1_700_000_000));
        $user = $this->enrolled();
        $secret = TwoFactor::secretOf($user);
        $step = Totp::timestep(now()->getTimestamp());

        $this->assertFalse(TwoFactor::verify($user, Totp::at($secret, $step - 2)));
        $this->assertTrue(TwoFactor::verify($user, Totp::at($secret, $step - 1)));
        $this->assertFalse(TwoFactor::verify($user->fresh(), Totp::at($secret, $step - 1)));
        $this->assertTrue(TwoFactor::verify($user->fresh(), Totp::at($secret, $step)));
    }

    public function test_the_secret_and_codes_are_encrypted_at_rest_and_hidden_from_serialisation(): void
    {
        $user = $this->enrolled('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
        $raw = DB::table('users')->where('id', $user->id)->first();

        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', (string) $raw->two_factor_secret);
        $this->assertStringNotContainsString('$2y$', (string) $raw->two_factor_recovery_codes, 'حتى التجزئات مشفّرة');
        $json = json_encode($user->toArray());
        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step', 'last_login_ip'] as $column) {
            $this->assertStringNotContainsString($column, $json);
        }
    }

    public function test_recovery_codes_are_ten_unique_hashed_and_each_works_once(): void
    {
        $codes = TwoFactor::newRecoveryCodes();
        $this->assertCount(10, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/', $code);
        }

        $user = User::factory()->create();
        $shown = TwoFactor::enable($user, Totp::newSecret(), 1);
        $stored = $user->fresh()->two_factor_recovery_codes;
        $this->assertCount(10, $stored);
        $this->assertStringNotContainsString($shown[0], json_encode($stored));

        $this->assertSame(9, TwoFactor::useRecoveryCode($user->fresh(), strtolower(str_replace('-', ' ', $shown[0]))));
        $this->assertNull(TwoFactor::useRecoveryCode($user->fresh(), $shown[0]), 'الرمز المستعمل لا يمر ثانية');
        $this->assertNull(TwoFactor::useRecoveryCode($user->fresh(), 'AAAAA-AAAAA'));
        $this->assertSame(9, TwoFactor::remainingRecoveryCodes($user->fresh()));

        $row = AuditLog::where('action', AuditAction::AuthMfaRecoveryUsed->value)->sole();
        $this->assertSame(['remaining' => 9], $row->changes);
        $this->assertSame(1, Notification::where('user_id', $user->id)->where('body', 'like', '%بقي 9%')->count());
    }

    public function test_enrolment_is_audited_without_the_secret_or_codes(): void
    {
        $user = User::factory()->create();
        $codes = TwoFactor::enable($user, 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 7);

        $row = AuditLog::where('action', AuditAction::AuthMfaEnrolled->value)->sole();
        $this->assertSame(['method' => 'totp'], $row->changes);
        $this->assertStringNotContainsString('JBSWY3DP', json_encode($row->toArray()));
        $this->assertStringNotContainsString($codes[0], json_encode($row->toArray()));
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->assertSame(7, $user->fresh()->two_factor_last_step);
    }
}
