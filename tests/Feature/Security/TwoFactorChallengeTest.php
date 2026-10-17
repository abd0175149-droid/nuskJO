<?php

namespace Tests\Feature\Security;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Services\Security\Mfa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\EnrollsSecondFactor;
use Tests\TestCase;

/**
 * تحدّي العامل الثاني (E27): كل جلسة جديدة لمن سجّل (ومنها جلسة كوكي «تذكرني») تمر بالتحدّي قبل أي مسار أو قناة بث،
 * والرمز لا يُقبل مرتين، والمحاولات محدودة ومدقّقة، ورمز الاسترداد يُستهلك مرة.
 */
class TwoFactorChallengeTest extends TestCase
{
    use EnrollsSecondFactor, RefreshDatabase;

    private function enrolledUser(array $permissions = ['whatsapp.view']): User
    {
        $user = User::factory()->withPermissions($permissions)->create(['password' => Hash::make('secret-pass-1')]);
        $this->enroll($user);

        return $user->fresh();
    }

    private function login(User $user, bool $remember = false)
    {
        return $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass-1', 'remember' => $remember]);
    }

    public function test_login_goes_to_the_challenge_and_nothing_else_is_reachable_before_it(): void
    {
        $user = $this->enrolledUser();

        $this->get('/whatsapp/inbox')->assertRedirect('/login');
        $this->login($user)->assertRedirect('/two-factor/challenge');
        $this->assertNotNull($user->fresh()->last_login_ip);

        $this->get('/whatsapp/inbox')->assertRedirect('/two-factor/challenge');
        $this->getJson('/api/notifications')->assertUnauthorized()->assertJson(['code' => 'MFA_REQUIRED']);
        $this->get('/two-factor/challenge')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/TwoFactorChallenge', false));

        $this->post('/two-factor/challenge', ['code' => $this->codeNow()])->assertRedirect('/whatsapp/inbox');
        $this->get('/whatsapp/inbox')->assertOk();
        $this->assertSame($user->id, session(Mfa::SESSION_KEY));
    }

    public function test_a_user_without_a_second_factor_logs_in_as_before(): void
    {
        $user = User::factory()->withPermissions(['whatsapp.view'])->create(['password' => Hash::make('secret-pass-1')]);

        $this->login($user)->assertRedirect('/');
        $this->get('/two-factor/challenge')->assertRedirect('/');
    }

    public function test_the_same_code_is_not_accepted_twice(): void
    {
        $user = $this->enrolledUser();
        $code = $this->codeNow();
        $this->login($user);
        $this->post('/two-factor/challenge', ['code' => $code])->assertRedirect('/');

        // جلسة جديدة على جهاز آخر بالرمز نفسه
        $this->app['session']->flush();
        $this->actingAs($user->fresh())->post('/two-factor/challenge', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_a_new_session_from_the_remember_cookie_is_challenged_too(): void
    {
        $user = $this->enrolledUser();
        $user->forceFill(['remember_token' => 'remember-token-123'])->saveQuietly();
        $recaller = Auth::guard('web')->getRecallerName();

        $this->withCookie($recaller, $user->id.'|remember-token-123|'.$user->password)
            ->get('/whatsapp/inbox')->assertRedirect('/two-factor/challenge');
        $this->assertAuthenticatedAs($user);
    }

    public function test_broadcast_channel_auth_is_refused_before_the_challenge(): void
    {
        $user = $this->enrolledUser();
        $this->login($user);

        $status = $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-user.'.$user->id])->status();
        $this->assertContains($status, [401, 403]);
    }

    public function test_the_sixth_attempt_in_a_minute_is_throttled(): void
    {
        $user = $this->enrolledUser();
        $this->login($user);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/two-factor/challenge', ['code' => '000000'])->assertRedirect();
        }
        $this->postJson('/two-factor/challenge', ['code' => '000000'])->assertStatus(429)->assertJson(['code' => 'MFA_THROTTLED']);
    }

    public function test_ten_failures_within_fifteen_minutes_sign_the_user_out_and_alert(): void
    {
        config(['audit.extended' => true]);
        $admin = User::factory()->admin()->create();
        $user = $this->enrolledUser();
        $this->login($user);

        foreach ([0, 1] as $round) {
            $this->travel(61)->seconds();
            for ($i = 0; $i < 5; $i++) {
                $response = $this->post('/two-factor/challenge', ['code' => '000000']);
            }
        }

        $response->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(1, AuditLog::where('action', AuditAction::AuthMfaLockout->value)->count());
        $this->assertSame(1, Notification::where('user_id', $user->id)->where('title', 'like', '%محاولات تحقق%')->count());
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'like', '%قفل التحقق%')->count());
    }

    public function test_a_recovery_code_works_once_and_tells_the_user_how_many_remain(): void
    {
        $user = User::factory()->withPermissions(['whatsapp.view'])->create(['password' => Hash::make('secret-pass-1')]);
        $codes = $this->enroll($user);

        $this->login($user->fresh());
        $this->post('/two-factor/challenge', ['recovery_code' => $codes[3]])->assertRedirect('/');
        $this->assertSame(1, Notification::where('user_id', $user->id)->where('body', 'like', '%بقي 9%')->count());

        $this->post('/logout');
        $this->login($user->fresh());
        $this->post('/two-factor/challenge', ['recovery_code' => $codes[3]])->assertSessionHasErrors('recovery_code');
    }

    public function test_the_emergency_switch_lets_users_in_and_leaves_an_hourly_trace(): void
    {
        config(['security.mfa.challenge' => 'off']);
        $user = $this->enrolledUser();

        $this->login($user)->assertRedirect('/');
        $this->get('/whatsapp/inbox')->assertOk();
        $this->get('/whatsapp/inbox')->assertOk();

        $this->assertSame(1, AuditLog::where('action', AuditAction::AuthMfaBypassed->value)->count());
    }
}
