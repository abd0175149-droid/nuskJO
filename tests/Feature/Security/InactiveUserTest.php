<?php

namespace Tests\Feature\Security;

use App\Models\FcmToken;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Services\NotificationService;
use App\Services\PushService;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** الحساب المعطّل يخرج في طلبه التالي، ولا تصله إشعارات، ورقمه الإداري يُعامَل كعميل. */
class InactiveUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_user_is_signed_out_on_the_next_page_request(): void
    {
        $user = User::factory()->withPermissions(['clients.view'])->create();
        $this->actingAs($user)->get('/clients')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)->get('/clients')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivated_user_gets_401_on_api_requests(): void
    {
        $user = User::factory()->withPermissions(['whatsapp.view'])->inactive()->create();

        $this->actingAs($user)->getJson('/api/notifications')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_changing_own_password_signs_out_other_sessions(): void
    {
        $user = User::factory()->withPermissions(['clients.view'])->create(['password' => Hash::make('old-password-1')]);
        $oldHash = $user->password;

        $this->actingAs($user)->withSession(['password_hash_web' => $oldHash])
            ->put('/profile/password', [
                'current_password' => 'old-password-1',
                'password' => 'new-password-2',
                'password_confirmation' => 'new-password-2',
            ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-password-2', $user->fresh()->password));

        // جهاز آخر ما زالت جلسته مختومة بالبصمة القديمة
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])
            ->get('/clients')->assertRedirect('/login');
    }

    public function test_profile_password_requires_eight_characters(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password-1')]);

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'old-password-1', 'password' => 'short7x', 'password_confirmation' => 'short7x',
        ])->assertSessionHasErrors('password');
    }

    public function test_inactive_admins_and_users_receive_no_notifications_or_pushes(): void
    {
        $active = User::factory()->admin()->create();
        $inactive = User::factory()->admin()->inactive()->create();
        FcmToken::create(['user_id' => $inactive->id, 'token' => 'tok-inactive']);

        NotificationService::notifyAdmins('عنوان', 'نص');
        WhatsAppInbox::notifyAdmins('عنوان', 'نص');
        NotificationService::broadcast('عنوان', 'نص');

        $this->assertSame(0, Notification::where('user_id', $inactive->id)->count());
        $this->assertGreaterThan(0, Notification::where('user_id', $active->id)->count());
        $this->assertSame(0, PushService::toUser($inactive->id, 'عنوان', 'نص'));
    }

    public function test_admin_number_of_a_deactivated_user_is_treated_as_a_customer(): void
    {
        $staff = User::factory()->create();
        $number = WaAdminNumber::factory()->create(['user_id' => $staff->id]);
        $this->assertNotNull(WaAdminNumber::forPhone($number->phone));

        $staff->forceFill(['is_active' => false])->save();
        $this->assertNull(WaAdminNumber::forPhone($number->phone));

        // رقم بلا حساب مرتبط لا يتأثر
        $unlinked = WaAdminNumber::factory()->create(['user_id' => null]);
        $this->assertNotNull(WaAdminNumber::forPhone($unlinked->phone));
    }

    public function test_admin_numbers_page_flags_a_deactivated_linked_user(): void
    {
        $staff = User::factory()->inactive()->create();
        WaAdminNumber::factory()->create(['user_id' => $staff->id]);

        $this->actingAs(User::factory()->admin()->create())->get('/whatsapp/admin-numbers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('numbers.0.user_inactive', true));
    }
}
