<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** صلاحيات المحاسبة وroles.manage (E03 T4): المنح بالهجرة، وحدود من يدير الأدوار وليس مديراً. */
class RolesManageTest extends TestCase
{
    use RefreshDatabase;

    private function permissionIds(array $slugs): array
    {
        return Permission::whereIn('slug', $slugs)->pluck('id')->all();
    }

    public function test_migration_adds_permissions_and_grants_without_removing_existing_ones(): void
    {
        $migration = require database_path('migrations/2026_10_14_000003_authz_permissions_and_grants.php');
        $accountant = Role::factory()->create(['slug' => 'accountant']);
        $custom = Permission::factory()->create(['slug' => 'clients.view', 'module' => 'clients']);
        $accountant->permissions()->attach($custom->id);
        Role::factory()->create(['slug' => 'admin']);

        $migration->up();
        $migration->up();   // إعادة التشغيل بلا أثر

        foreach (['accounting.view', 'accounting.manage', 'accounting.close', 'roles.manage'] as $slug) {
            $this->assertSame(1, Permission::where('slug', $slug)->count());
        }
        $this->assertEqualsCanonicalizing(['clients.view', 'accounting.view'], $accountant->fresh()->permissions->pluck('slug')->all());
        $this->assertSame(4, Role::where('slug', 'admin')->first()->permissions()->count());
    }

    public function test_role_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->app->make(RolePermissionSeeder::class)->run();
            $this->fail('كان يجب أن يرفض');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('لا تشغّله على الإنتاج', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_role_manager_cannot_grant_or_revoke_sensitive_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->withPermissions(['roles.manage'], 'role-manager')->create();
        $sales = Role::where('slug', 'sales')->first();
        $current = $sales->permissions()->pluck('permissions.id')->all();

        $this->actingAs($manager)->put("/roles/{$sales->id}", [
            'name' => $sales->name, 'permissions' => array_merge($current, $this->permissionIds(['users.update'])),
        ])->assertForbidden();

        $this->actingAs($manager)->post('/roles', [
            'name' => 'دور جديد', 'permissions' => $this->permissionIds(['clients.view', 'settings.update']),
        ])->assertForbidden();

        // غير الحساسة مسموحة
        $this->actingAs($manager)->put("/roles/{$sales->id}", [
            'name' => $sales->name, 'permissions' => array_merge($current, $this->permissionIds(['agents.update'])),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($sales->fresh()->hasPermission('agents.update'));
        $this->assertFalse($sales->fresh()->hasPermission('users.update'));
    }

    public function test_role_manager_cannot_touch_the_admin_role_or_their_own(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->withPermissions(['roles.manage', 'clients.view'], 'role-manager')->create();
        $own = $manager->role;
        $admin = Role::where('slug', 'admin')->first();

        $this->actingAs($manager)->get("/roles/{$own->id}/edit")->assertForbidden();
        $this->actingAs($manager)->put("/roles/{$own->id}", [
            'name' => $own->name, 'permissions' => $this->permissionIds(['roles.manage', 'clients.view', 'invoices.approve']),
        ])->assertForbidden();
        $this->actingAs($manager)->get("/roles/{$admin->id}/edit")->assertForbidden();

        $this->assertFalse($own->fresh()->hasPermission('invoices.approve'));
    }

    public function test_sales_lose_the_accounting_menu_but_the_accountant_keeps_it(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $accountant = User::factory()->create();
        $accountant->forceFill(['role_id' => Role::where('slug', 'accountant')->value('id')])->save();
        $sales = User::factory()->create();
        $sales->forceFill(['role_id' => Role::where('slug', 'sales')->value('id')])->save();

        $this->assertTrue($accountant->fresh()->can('accounting.view'));
        $this->assertFalse($sales->fresh()->can('accounting.view'));
        $this->assertTrue($sales->fresh()->can('reports.view'), 'التقارير باقية للمبيعات');
        $this->assertSame(0, DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->whereIn('permissions.slug', ['accounting.manage', 'accounting.close', 'roles.manage'])
            ->where('role_id', '!=', Role::where('slug', 'admin')->value('id'))
            ->count(), 'لا منح للصلاحيات الأعلى إلا للمدير افتراضياً');
    }
}
