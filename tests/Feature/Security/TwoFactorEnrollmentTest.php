<?php

namespace Tests\Feature\Security;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Security\Mfa;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EnrollsSecondFactor;
use Tests\TestCase;

/**
 * التسجيل من صفحة «الأمان» (E27): سر معلّق في الجلسة لا في users حتى يؤكَّد، ورموز الاسترداد مرة واحدة في JSON بلا
 * تخزين، والتفعيل والتعطيل وتجديد الرموز بعد تأكيد هوية حديث، والتعطيل محروس.
 */
class TwoFactorEnrollmentTest extends TestCase
{
    use EnrollsSecondFactor, RefreshDatabase;

    private function begin(User $user): array
    {
        return $this->actingAs($user)->withSession($this->passedSession($user))
            ->postJson('/profile/two-factor')->assertOk()->json();
    }

    public function test_starting_returns_a_local_qr_and_grouped_secret_without_touching_users(): void
    {
        $this->staffMfaOn();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->withSession($this->passedSession($user))->postJson('/profile/two-factor');

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('qr'));
        $this->assertStringContainsString('<svg', base64_decode(substr($response->json('qr'), 26)));
        $this->assertMatchesRegularExpression('/^([A-Z2-7]{4} ){7}[A-Z2-7]{4}$/', $response->json('secret'));
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('two_factor_secret'));
        $this->assertStringNotContainsString(str_replace(' ', '', $response->json('secret')), (string) session(Mfa::PENDING_KEY));
    }

    public function test_enrolment_is_hidden_while_the_flag_is_off(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession($this->passedSession($user))->postJson('/profile/two-factor')->assertNotFound();
    }

    public function test_starting_needs_a_recent_identity_confirmation_even_with_step_up_off(): void
    {
        $this->staffMfaOn();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/profile/two-factor')->assertStatus(423)->assertJson(['code' => 'STEP_UP_REQUIRED']);
    }

    public function test_a_wrong_code_is_refused_and_the_right_one_enrols_once_with_ten_codes(): void
    {
        $this->staffMfaOn();
        $user = User::factory()->create();
        $secret = str_replace(' ', '', $this->begin($user)['secret']);

        $this->postJson('/profile/two-factor/confirm', ['code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertFalse($user->fresh()->hasTwoFactor());

        $response = $this->postJson('/profile/two-factor/confirm', ['code' => Totp::at($secret, Totp::timestep(now()->getTimestamp()))]);

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertCount(10, $response->json('recovery_codes'));
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->assertSame($user->id, session(Mfa::SESSION_KEY), 'هذه الجلسة اجتازت العامل الثاني');
        $this->assertNull(session(Mfa::PENDING_KEY));

        $row = AuditLog::where('action', AuditAction::AuthMfaEnrolled->value)->sole();
        $dump = json_encode($row->toArray());
        $this->assertStringNotContainsString($secret, $dump);
        $this->assertStringNotContainsString($response->json('recovery_codes.0'), $dump);

        // الرموز لا تعود في الصفحة ولا في أي prop
        $page = $this->get('/profile/security')->assertOk()->getContent();
        $this->assertStringNotContainsString($response->json('recovery_codes.0'), $page);
        $this->assertStringNotContainsString($secret, $page);
    }

    public function test_regenerating_codes_replaces_the_old_ones(): void
    {
        $user = User::factory()->create();
        $old = $this->enroll($user);

        $new = $this->actingAs($user)->withSession($this->passedSession($user))
            ->postJson('/profile/two-factor/recovery-codes')->assertOk()->json('recovery_codes');

        $this->assertCount(10, $new);
        $this->assertSame([], array_intersect($old, $new));
        $this->assertSame(1, AuditLog::where('action', AuditAction::AuthMfaCodesRegenerated->value)->count());
    }

    public function test_disabling_clears_the_factor_and_signs_out_other_devices(): void
    {
        $user = User::factory()->create();
        $this->enroll($user);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->withSession($this->passedSession($user))->deleteJson('/profile/two-factor')->assertOk();

        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('two_factor_secret'));
        $this->assertFalse(DB::table('sessions')->where('id', 'other-device')->exists());
        $this->assertSame(['by' => 'self'], AuditLog::where('action', AuditAction::AuthMfaDisabled->value)->sole()->changes);
    }

    public function test_disabling_needs_a_fresh_code_and_is_refused_when_required_or_for_the_last_admin_with_a_factor(): void
    {
        $admin = User::factory()->admin()->create();
        $this->enroll($admin);

        // بلا تأكيد حديث: 423 حتى والعلم step_up مطفأ
        $this->actingAs($admin)->withSession($this->passedSession($admin, stepUp: false))->deleteJson('/profile/two-factor')->assertStatus(423);

        // آخر مدير نشط بعامل ثانٍ
        $this->actingAs($admin)->withSession($this->passedSession($admin))->deleteJson('/profile/two-factor')
            ->assertStatus(422)->assertJsonValidationErrors('code');

        // مدير آخر بعامل، لكن الفرض on لأصحاب الصلاحيات الحساسة
        $this->enroll(User::factory()->admin()->create());
        $this->staffMfaOn();
        config(['security.mfa.enforce' => 'on']);
        $this->deleteJson('/profile/two-factor')->assertStatus(422);

        config(['security.mfa.enforce' => 'warn']);
        $this->deleteJson('/profile/two-factor')->assertOk();
        $this->assertFalse($admin->fresh()->hasTwoFactor());
    }
}
