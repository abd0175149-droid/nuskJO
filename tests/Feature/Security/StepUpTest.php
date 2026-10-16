<?php

namespace Tests\Feature\Security;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Services\Security\StepUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as Router;
use Tests\Concerns\EnrollsSecondFactor;
use Tests\Concerns\PatchesWhatsAppSettings;
use Tests\TestCase;

/**
 * التحقق المعزّز (E27): الأفعال الحساسة تطلب تأكيد الهوية كل ربع ساعة على الأكثر وتُعاد بعده، لا تصفح الصفحات.
 * JSON يتلقى 423، وزيارة Inertia تعود بعلامة، وGET يمر بصفحة التأكيد ثم يعود. والإيقاف الطارئ لا يُعاق.
 */
class StepUpTest extends TestCase
{
    use EnrollsSecondFactor, PatchesWhatsAppSettings, RefreshDatabase;

    private User $admin;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.step_up' => true]);
        $this->admin = User::factory()->admin()->create(['password' => Hash::make('admin-pass-1')]);
        $this->target = User::factory()->create();
    }

    private function resetPassword(array $headers = [])
    {
        return $this->withHeaders($headers)->put("/users/{$this->target->id}/reset-password", [
            'password' => 'new-password-9', 'password_confirmation' => 'new-password-9',
        ]);
    }

    private function stale(User $user): array
    {
        return [StepUp::SESSION_KEY => now()->subMinutes(16)->getTimestamp()];
    }

    public function test_a_stale_window_bounces_inertia_visits_and_answers_423_to_json(): void
    {
        $this->actingAs($this->admin)->withSession($this->stale($this->admin))->from('/users');

        $this->putJson("/users/{$this->target->id}/reset-password", ['password' => 'new-password-9', 'password_confirmation' => 'new-password-9'])
            ->assertStatus(423)->assertJson(['code' => 'STEP_UP_REQUIRED']);

        // (ترويسة Inertia تبقى لما بعدها من طلبات الاختبار، فهي الأخيرة)
        $this->resetPassword(['X-Inertia' => 'true'])->assertRedirect('/users')->assertSessionHas('stepup_required', true);
        $this->assertFalse(Hash::check('new-password-9', $this->target->fresh()->password));
        $this->assertNull(session('url.intended'), 'لا وجهة محفوظة لطلب غير GET');
    }

    public function test_confirming_with_the_password_lets_the_same_request_through(): void
    {
        $this->actingAs($this->admin)->withSession($this->stale($this->admin))->from('/users');

        $this->postJson('/security/confirm', ['password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/security/confirm', ['password' => 'admin-pass-1'])->assertOk()->assertJson(['ok' => true]);

        $this->resetPassword(['X-Inertia' => 'true'])->assertRedirect('/users')->assertSessionHas('success');
        $this->assertTrue(Hash::check('new-password-9', $this->target->fresh()->password));
    }

    public function test_a_user_with_a_second_factor_confirms_with_a_code_not_the_password(): void
    {
        config(['audit.extended' => true]);
        $this->enroll($this->admin);
        $this->actingAs($this->admin->fresh())->withSession($this->passedSession($this->admin, stepUp: false));

        $this->postJson('/security/confirm', ['password' => 'admin-pass-1'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/security/confirm', ['code' => $this->codeNow()])->assertOk();
        $this->assertSame(1, AuditLog::where('action', AuditAction::AuthStepUp->value)->count());
        $this->resetPassword()->assertRedirect();
        $this->assertTrue(Hash::check('new-password-9', $this->target->fresh()->password));
    }

    public function test_a_get_action_goes_through_the_confirm_page_and_comes_back(): void
    {
        config(['audit.page' => true]);
        $this->actingAs($this->admin)->withSession($this->stale($this->admin));

        $this->get('/audit-logs/export')->assertRedirect('/security/confirm');
        $this->get('/security/confirm')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/ConfirmStepUp', false)->where('method', 'password'));
        $this->post('/security/confirm', ['password' => 'admin-pass-1'])->assertRedirect('/audit-logs/export');
    }

    public function test_the_emergency_stop_is_never_blocked_but_resuming_is(): void
    {
        WaBotSetting::current();
        $this->withSession($this->stale($this->admin));

        $this->killSwitch($this->admin, 'on')->assertOk();
        $this->assertTrue((bool) WaBotSetting::current()->fresh()->wa_suspended);

        $this->killSwitch($this->admin, 'off')->assertStatus(423);
        $this->assertTrue((bool) WaBotSetting::current()->fresh()->wa_suspended);

        $this->postJson('/security/confirm', ['password' => 'admin-pass-1'])->assertOk();
        $this->killSwitch($this->admin, 'off')->assertOk();
        $this->assertFalse((bool) WaBotSetting::current()->fresh()->wa_suspended);
    }

    public function test_with_the_flag_off_listed_actions_pass_but_factor_management_still_asks(): void
    {
        config(['features.env.step_up' => false]);
        $this->actingAs($this->admin)->withSession($this->stale($this->admin))->from('/users');

        $this->resetPassword()->assertRedirect('/users')->assertSessionHas('success');
        $this->postJson('/profile/two-factor/recovery-codes')->assertStatus(423);
    }

    public function test_the_window_is_opened_by_login_and_lasts_fifteen_minutes(): void
    {
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'admin-pass-1'])->assertRedirect('/');
        $this->from('/users')->resetPassword()->assertSessionHas('success');

        $this->travel(16)->minutes();
        $this->from('/users')->resetPassword(['X-Inertia' => 'true'])->assertSessionHas('stepup_required', true);
    }

    public function test_every_listed_route_exists_and_carries_the_middleware_after_its_permission(): void
    {
        $byKey = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $byKey[$route->getName() ?? $methods[0].' '.$route->uri()] = $route;
        }

        foreach (config('security.stepup.routes') as $key) {
            $this->assertArrayHasKey($key, $byKey, "مسار غير موجود في security.stepup.routes: {$key}");
            $middleware = $byKey[$key]->gatherMiddleware();
            $this->assertContains('stepup', $middleware, $key);
            $perm = collect($middleware)->search(fn ($m) => is_string($m) && str_starts_with($m, 'perm:'));
            $this->assertNotFalse($perm, "{$key} بلا صلاحية معلنة");
            $this->assertGreaterThan($perm, array_search('stepup', $middleware, true), "{$key}: الصلاحية قبل التحقق المعزّز");
        }

        foreach (['invoices.approve', 'receipts.approve', 'disbursements.approve', 'payrolls.approve', 'advances.approve', 'accounting.close-year',
            'users.store', 'roles.update', 'settings.update', 'whatsapp.admin-numbers.store', 'audit-logs.export'] as $sensitive) {
            $this->assertContains($sensitive, config('security.stepup.routes'));
        }
        // التصفح اليومي والإيقاف الطارئ ليسا هنا
        foreach (['invoices.index', 'whatsapp.inbox', 'POST api/whatsapp/settings/kill-switch'] as $daily) {
            $this->assertNotContains($daily, config('security.stepup.routes'));
        }
    }
}
