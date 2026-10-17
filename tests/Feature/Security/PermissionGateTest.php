<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** مسار واحد لفحص الصلاحية عبر Gate::before. */
class PermissionGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_permissions_grant_and_everything_else_is_denied(): void
    {
        $user = User::factory()->withPermissions(['clients.view'])->create();

        $this->assertTrue($user->can('clients.view'));
        $this->assertFalse($user->can('clients.delete'));
        $this->assertFalse($user->can('admin'));
        $this->assertFalse(Gate::forUser($user)->allows('users.update'));
    }

    public function test_admin_passes_every_check_including_the_admin_ability(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertTrue($admin->can('anything.at_all'));
        $this->assertTrue($admin->can('admin'));
    }

    public function test_inactive_user_is_denied_everything_even_as_admin(): void
    {
        $admin = User::factory()->admin()->inactive()->create();
        $sales = User::factory()->withPermissions(['clients.view'])->inactive()->create();

        $this->assertFalse($admin->can('admin'));
        $this->assertFalse($admin->can('users.view'));
        $this->assertFalse($sales->can('clients.view'));
    }

    public function test_permissions_are_loaded_once_per_user_instance(): void
    {
        $user = User::factory()->withPermissions(['clients.view', 'agents.view'])->create()->fresh();

        DB::enableQueryLog();
        foreach (range(1, 10) as $_) {
            $user->can('clients.view');
            $user->can('agents.view');
            $user->can('invoices.approve');
        }
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $queries, 'الدور وصلاحياته مرة واحدة، لا لكل فحص');
    }

    public function test_changing_role_refreshes_permissions(): void
    {
        $user = User::factory()->withPermissions(['clients.view'])->create();
        $this->assertTrue($user->can('clients.view'));

        $other = User::factory()->withPermissions(['agents.view'], 'accountant')->create();
        $user->forceFill(['role_id' => $other->role_id])->save();
        $user->unsetRelation('role');

        $this->assertFalse($user->can('clients.view'));
        $this->assertTrue($user->can('agents.view'));
    }
}
