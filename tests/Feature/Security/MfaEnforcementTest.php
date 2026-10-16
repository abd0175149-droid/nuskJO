<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EnrollsSecondFactor;
use Tests\TestCase;

/**
 * فرض التسجيل على أصحاب الصلاحيات الحساسة (E27): warn يمرّر الطلب مع شريط التنبيه، وon بعد تاريخه لا يفتح إلا صفحة
 * «الأمان». لا فرض والعلم staff_mfa مطفأ، ولا أثر على من لا صلاحية حساسة له.
 */
class MfaEnforcementTest extends TestCase
{
    use EnrollsSecondFactor, RefreshDatabase;

    private function approver(): User
    {
        return User::factory()->withPermissions(['invoices.view', 'invoices.approve'], 'accountant')->create();
    }

    public function test_warn_lets_requests_through_and_shares_the_banner(): void
    {
        $this->staffMfaOn();
        config(['security.mfa.enforce' => 'warn', 'security.mfa.enforce_from' => now()->addDays(14)->toDateString()]);

        $this->actingAs($this->approver())->get('/invoices')->assertOk()
            ->assertInertia(fn ($page) => $page->where('security.mfa_needed', true)
                ->where('security.mfa_enforce_from', now()->addDays(14)->toDateString()));
    }

    public function test_on_after_its_date_allows_only_the_security_page(): void
    {
        $this->staffMfaOn();
        config(['security.mfa.enforce' => 'on', 'security.mfa.enforce_from' => now()->subDay()->toDateString()]);
        $user = $this->approver();

        $this->actingAs($user)->get('/invoices')->assertRedirect('/profile/security')->assertSessionHas('error');
        $this->getJson('/api/notifications')->assertForbidden()->assertJson(['code' => 'MFA_ENROLL_REQUIRED']);
        $this->get('/profile/security')->assertOk()->assertInertia(fn ($page) => $page->where('security.required', true)->where('security.enforcement', 'on'));
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_on_before_its_date_behaves_as_warn(): void
    {
        $this->staffMfaOn();
        config(['security.mfa.enforce' => 'on', 'security.mfa.enforce_from' => now()->addDays(3)->toDateString()]);

        $this->actingAs($this->approver())->get('/invoices')->assertOk()->assertInertia(fn ($page) => $page->where('security.mfa_needed', true));
    }

    public function test_enrolled_users_and_staff_without_sensitive_permissions_are_unaffected(): void
    {
        $this->staffMfaOn();
        config(['security.mfa.enforce' => 'on']);

        $sales = User::factory()->withPermissions(['clients.view'])->create();
        $this->actingAs($sales)->get('/clients')->assertOk()->assertInertia(fn ($page) => $page->where('security.mfa_needed', false));

        $approver = $this->approver();
        $this->enroll($approver);
        $this->actingAs($approver->fresh())->withSession($this->passedSession($approver))->get('/invoices')->assertOk();
    }

    public function test_no_enforcement_while_enrolment_is_closed(): void
    {
        config(['security.mfa.enforce' => 'on']);

        $this->actingAs($this->approver())->get('/invoices')->assertOk()->assertInertia(fn ($page) => $page->where('security.mfa_needed', false));
    }
}
