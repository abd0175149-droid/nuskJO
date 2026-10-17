<?php

namespace Tests\Feature\Security;

use App\Models\FcmToken;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\AdminInvariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * قواعد إدارة المستخدمين والأدوار: من يسند أي دور، وبقاء مدير نشط دائماً.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function adminRole(): Role
    {
        return Role::firstOrCreate(['slug' => 'admin'], ['name' => 'مدير']);
    }

    private function roleWith(array $slugs, string $slug): Role
    {
        return User::factory()->withPermissions($slugs, $slug)->create()->role;
    }

    private function userPayload(Role $role, array $extra = []): array
    {
        return array_merge([
            'name' => 'موظف جديد',
            'email' => 'new'.uniqid().'@example.com',
            'password' => 'secret-pass-1',
            'role_id' => $role->id,
            'is_active' => true,
        ], $extra);
    }

    public function test_viewing_users_requires_permission(): void
    {
        $plain = User::factory()->withPermissions(['clients.view'], 'plain')->create();

        $this->actingAs($plain)->get('/users')->assertForbidden();
    }

    public function test_non_admin_cannot_assign_admin_role(): void
    {
        $manager = User::factory()->withPermissions(['users.view', 'users.create'], 'hr')->create();

        $this->actingAs($manager)->post('/users', $this->userPayload($this->adminRole()))->assertForbidden();

        $this->assertSame(0, User::where('role_id', $this->adminRole()->id)->count());
    }

    public function test_non_admin_cannot_assign_role_with_sensitive_permission(): void
    {
        $manager = User::factory()->withPermissions(['users.view', 'users.create', 'users.update'], 'hr')->create();
        $sensitive = $this->roleWith(['users.update'], 'deputy');

        $this->actingAs($manager)->post('/users', $this->userPayload($sensitive))->assertForbidden();
    }

    public function test_non_admin_cannot_assign_role_broader_than_own(): void
    {
        $manager = User::factory()->withPermissions(['users.view', 'users.create', 'clients.view'], 'hr')->create();
        $broader = $this->roleWith(['clients.view', 'invoices.approve'], 'senior');

        $this->actingAs($manager)->post('/users', $this->userPayload($broader))->assertForbidden();
    }

    public function test_non_admin_can_assign_role_within_own_permissions(): void
    {
        $manager = User::factory()->withPermissions(['users.view', 'users.create', 'clients.view'], 'hr')->create();
        $narrow = $this->roleWith(['clients.view'], 'junior');
        $payload = $this->userPayload($narrow);

        $this->actingAs($manager)->post('/users', $payload)->assertRedirect();

        $created = User::where('email', $payload['email'])->first();
        $this->assertSame($narrow->id, $created->role_id);
        $this->assertTrue($created->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.create', 'entity_id' => $created->id]);
    }

    public function test_non_admin_cannot_edit_an_admin(): void
    {
        $manager = User::factory()->withPermissions(['users.view', 'users.update'], 'hr')->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($manager)->put("/users/{$admin->id}", [
            'name' => 'x', 'email' => $admin->email, 'role_id' => $admin->role_id, 'is_active' => false,
        ])->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_user_cannot_change_own_role_or_deactivate_self(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();
        $sales = $this->roleWith(['clients.view'], 'sales');

        $this->actingAs($admin)->put("/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role_id' => $sales->id, 'is_active' => true,
        ])->assertSessionHasErrors('role_id');

        $this->actingAs($admin)->put("/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role_id' => $admin->role_id, 'is_active' => false,
        ])->assertSessionHasErrors('role_id');

        $this->assertTrue($admin->fresh()->isAdmin());
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_last_active_admin_cannot_be_demoted_deactivated_or_deleted(): void
    {
        $last = User::factory()->admin()->create();
        // مدير معطّل ما زالت جلسته مفتوحة: يخرج في طلبه التالي (active) قبل أي فحص
        $stale = User::factory()->admin()->inactive()->create();
        $sales = $this->roleWith(['clients.view'], 'sales');

        $this->actingAs($stale)->put("/users/{$last->id}", [
            'name' => $last->name, 'email' => $last->email, 'role_id' => $last->role_id, 'is_active' => false,
        ])->assertRedirect('/login');
        $this->assertGuest();

        $this->actingAs($stale)->put("/users/{$last->id}", [
            'name' => $last->name, 'email' => $last->email, 'role_id' => $sales->id, 'is_active' => true,
        ])->assertRedirect('/login');

        $this->actingAs($stale)->delete("/users/{$last->id}")->assertRedirect('/login');

        $fresh = $last->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->isAdmin());
        $this->assertTrue($fresh->is_active);
    }

    public function test_invariant_message_reaches_the_admin_when_the_write_is_blocked(): void
    {
        // الطلب يمرّ الفحوص لكن الكتابة نفسها ترفض (سباق بين مديرين): الرسالة تصل ولا يتغير شيء
        $actor = User::factory()->admin()->create();
        $target = User::factory()->admin()->create();
        $actor->forceFill(['is_active' => false])->saveQuietly();   // صار الهدف آخر مدير نشط بعد فحص الطلب
        $actor->is_active = true;   // النسخة في الذاكرة ما زالت ترى نفسها نشطة كما في منتصف الطلب
        $actor->syncOriginal();

        $this->actingAs($actor)->put("/users/{$target->id}", [
            'name' => $target->name, 'email' => $target->email, 'role_id' => $target->role_id, 'is_active' => false,
        ])->assertSessionHas('error', AdminInvariant::BLOCKED_MESSAGE);

        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_admin_invariant_is_checked_inside_the_write(): void
    {
        $only = User::factory()->admin()->create();
        $this->assertFalse(AdminInvariant::guardedUpdate($only, ['is_active' => false]));
        $this->assertFalse(AdminInvariant::guardedDelete($only));

        $second = User::factory()->admin()->create();
        $this->assertTrue(AdminInvariant::guardedUpdate($only, ['is_active' => false]));
        $this->assertFalse(AdminInvariant::guardedUpdate($second, ['is_active' => false]));
    }

    public function test_admin_can_demote_another_admin_when_one_remains(): void
    {
        $a = User::factory()->admin()->create();
        $b = User::factory()->admin()->create();
        $sales = $this->roleWith(['clients.view'], 'sales');

        $this->actingAs($a)->put("/users/{$b->id}", [
            'name' => $b->name, 'email' => $b->email, 'role_id' => $sales->id, 'is_active' => true,
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame($sales->id, $b->fresh()->role_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.role_change', 'entity_id' => $b->id]);
    }

    public function test_password_reset_is_admin_only_and_signs_user_out(): void
    {
        $manager = User::factory()->withPermissions(['users.view', 'users.update'], 'hr')->create();
        $admin = User::factory()->admin()->create();
        $target = User::factory()->withPermissions(['clients.view'], 'sales')->create();

        $this->actingAs($manager)->put("/users/{$target->id}/reset-password", [
            'password' => 'new-secret-12', 'password_confirmation' => 'new-secret-12',
        ])->assertForbidden();

        DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $target->id, 'payload' => 'x', 'last_activity' => time()]);
        FcmToken::create(['user_id' => $target->id, 'token' => 'tok-1']);
        $oldRemember = $target->remember_token;

        $this->actingAs($admin)->put("/users/{$target->id}/reset-password", [
            'password' => 'new-secret-12', 'password_confirmation' => 'new-secret-12',
        ])->assertSessionHas('success');

        $fresh = $target->fresh();
        $this->assertTrue(Hash::check('new-secret-12', $fresh->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertSame(0, FcmToken::where('user_id', $target->id)->count());
        $this->assertNotSame($oldRemember, $fresh->remember_token);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset', 'entity_id' => $target->id]);
    }

    public function test_non_admin_cannot_change_password_through_update(): void
    {
        $manager = User::factory()->withPermissions(['users.view', 'users.update', 'clients.view'], 'hr')->create();
        $target = User::factory()->withPermissions(['clients.view'], 'sales')->create();

        $this->actingAs($manager)->put("/users/{$target->id}", [
            'name' => $target->name, 'email' => $target->email, 'role_id' => $target->role_id, 'password' => 'hijack-pass-1',
        ])->assertForbidden();
    }

    public function test_role_management_is_admin_only(): void
    {
        $manager = User::factory()->withPermissions(['users.view', 'users.create', 'users.update'], 'hr')->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($manager)->get('/roles')->assertForbidden();
        $this->actingAs($manager)->post('/roles', ['name' => 'x', 'permissions' => []])->assertForbidden();

        $this->actingAs($admin)->post('/roles', ['name' => 'محاسب مساعد', 'permissions' => []])->assertSessionHas('success');
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.create']);
    }

    public function test_role_and_active_flag_are_not_mass_assignable(): void
    {
        $user = new User(['name' => 'x', 'email' => 'x@example.com', 'password' => 'p', 'role_id' => 99, 'is_active' => false]);

        $this->assertNull($user->role_id);
        $this->assertNull($user->is_active);
    }

    public function test_employee_with_new_login_follows_role_assignment_rules(): void
    {
        $hr = User::factory()->withPermissions(['employees.create', 'clients.view'], 'hr')->create();

        $payload = [
            'user_mode' => 'new',
            'new_user_name' => 'موظف',
            'new_user_email' => 'emp'.uniqid().'@example.com',
            'new_user_password' => 'secret-pass-1',
            'new_user_role_id' => $this->adminRole()->id,
            'hire_date' => now()->toDateString(),
            'contract_type' => 'full_time',
            'country' => 'JO',
            'currency' => 'JOD',
            'basic_salary' => 500, 'housing_allowance' => 0, 'transport_allowance' => 0, 'other_allowance' => 0,
            'overtime_calc_method' => 'multiplier', 'overtime_multiplier' => 1.5,
        ];

        // بلا users.create: ممنوع إنشاء حساب دخول أصلاً
        $this->actingAs($hr)->post('/employees', $payload)->assertForbidden();

        // مع users.create: ممنوع إسناد دور المدير
        $hr2 = User::factory()->withPermissions(['employees.create', 'users.create', 'clients.view'], 'hr2')->create();
        $this->actingAs($hr2)->post('/employees', $payload)->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => $payload['new_user_email']]);

        // ودور ضمن صلاحياته مسموح
        $junior = $this->roleWith(['clients.view'], 'junior');
        $this->actingAs($hr2)->post('/employees', ['new_user_role_id' => $junior->id] + $payload)->assertRedirect();
        $this->assertSame($junior->id, User::where('email', $payload['new_user_email'])->value('role_id'));
    }

    public function test_restore_admin_command_restores_without_touching_other_roles(): void
    {
        $sales = $this->roleWith(['clients.view'], 'sales');
        $user = User::factory()->withPermissions(['clients.view'], 'sales')->inactive()->create();

        $this->artisan('auth:restore-admin', ['email' => $user->email])->assertSuccessful();

        $fresh = $user->fresh();
        $this->assertTrue($fresh->isAdmin());
        $this->assertTrue($fresh->is_active);
        $this->assertTrue($sales->fresh()->hasPermission('clients.view'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.restore_admin', 'entity_id' => $user->id]);
    }
}
