<?php

namespace Tests\Feature\Security;

use App\Enums\AuditAction;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EnrollsSecondFactor;
use Tests\TestCase;

/**
 * الاسترداد (E27): المدير يعيد تعيين عامل موظف فقد هاتفه بسبب مدقَّق ويُخرجه من أجهزته، لا حسابه هو، ولا آخر مدير
 * نشط بعامل ثانٍ؛ وذلك بأمر الطوارئ من الخادم وحده، مع بقاء auth:restore-admin يعمل.
 */
class MfaResetTest extends TestCase
{
    use EnrollsSecondFactor, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function reset(User $target, string $reason = 'فقد هاتفه في السفر')
    {
        return $this->actingAs($this->admin)->withSession($this->passedSession($this->admin))
            ->from('/users')->post("/users/{$target->id}/two-factor/reset", ['reason' => $reason]);
    }

    public function test_an_admin_resets_an_employee_with_a_reason_and_signs_them_out_everywhere(): void
    {
        $employee = User::factory()->withPermissions(['invoices.view'])->create();
        $this->enroll($employee);
        DB::table('sessions')->insert(['id' => 'emp-session', 'user_id' => $employee->id, 'payload' => '', 'last_activity' => time()]);

        $this->reset($employee, 'قصير')->assertSessionHasErrors('reason');
        $this->reset($employee)->assertRedirect('/users')->assertSessionHas('success');

        $fresh = $employee->fresh();
        $this->assertFalse($fresh->hasTwoFactor());
        $this->assertNull(DB::table('users')->where('id', $employee->id)->value('two_factor_secret'));
        $this->assertNull(DB::table('users')->where('id', $employee->id)->value('two_factor_recovery_codes'));
        $this->assertFalse(DB::table('sessions')->where('user_id', $employee->id)->exists());

        $row = AuditLog::where('action', AuditAction::AuthMfaReset->value)->sole();
        $this->assertSame(AuditSeverity::Warn->value, $row->severity);
        $this->assertSame(['reason' => 'فقد هاتفه في السفر', 'by' => 'admin'], $row->changes);
        $this->assertSame(1, Notification::where('user_id', $employee->id)->where('title', 'like', '%أُعيد تعيين%')->count());
    }

    public function test_own_account_and_non_admins_are_refused(): void
    {
        $this->enroll($this->admin);
        $this->enroll(User::factory()->admin()->create());
        $this->reset($this->admin)->assertForbidden();

        $manager = User::factory()->withPermissions(['users.view', 'users.update'])->create();
        $employee = User::factory()->create();
        $this->enroll($employee);
        $this->actingAs($manager)->post("/users/{$employee->id}/two-factor/reset", ['reason' => 'سبب كافٍ للاختبار'])->assertForbidden();
        $this->assertTrue($employee->fresh()->hasTwoFactor());
    }

    public function test_the_last_active_admin_with_a_factor_is_reset_only_from_the_console(): void
    {
        $owner = User::factory()->admin()->create(['email' => 'owner@example.test']);
        $this->enroll($owner);

        $this->reset($owner)->assertSessionHas('error');
        $this->assertTrue($owner->fresh()->hasTwoFactor());

        $this->artisan('security:mfa-reset', ['email' => 'owner@example.test', '--reason' => 'فقد الهاتف ورموز الاسترداد'])
            ->expectsQuestion('للتأكيد اكتب البريد مرة ثانية ('.$owner->name.')', 'someone@else.test')
            ->assertFailed();
        $this->assertTrue($owner->fresh()->hasTwoFactor());

        $this->artisan('security:mfa-reset', ['email' => 'owner@example.test', '--reason' => 'فقد الهاتف ورموز الاسترداد'])
            ->expectsQuestion('للتأكيد اكتب البريد مرة ثانية ('.$owner->name.')', 'owner@example.test')
            ->assertSuccessful();
        $this->assertFalse($owner->fresh()->hasTwoFactor());

        $row = AuditLog::where('action', AuditAction::AuthMfaReset->value)->sole();
        $this->assertSame(['system', 'artisan:security:mfa-reset'], [$row->actor_type, $row->actor_ref]);
        $this->assertSame('console', $row->changes['by']);
    }

    public function test_restore_admin_still_works_and_points_to_the_reset_command(): void
    {
        $owner = User::factory()->create(['email' => 'boss@example.test']);
        $this->enroll($owner);

        $this->artisan('auth:restore-admin', ['email' => 'boss@example.test'])
            ->expectsOutputToContain('security:mfa-reset boss@example.test')
            ->assertSuccessful();
        $this->assertTrue($owner->fresh()->isAdmin());
    }
}
