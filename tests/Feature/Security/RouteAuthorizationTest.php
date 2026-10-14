<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * «مغلق افتراضياً»: كل مسار يتطلب الدخول يعلن صلاحيته أو يكون في authz.allowlist بسبب موثّق،
 * والحارس يرفض ما لم يُعلن في وضع enforce ويسجّله في وضع log.
 */
class RouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function protectedRoutes(): array
    {
        return collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('auth', $r->gatherMiddleware(), true))
            ->all();
    }

    private function key(Route $r): string
    {
        $method = collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->first();

        return $r->getName() ?? "{$method} {$r->uri()}";
    }

    public function test_every_authenticated_route_declares_a_permission_or_is_allowlisted(): void
    {
        $unmapped = [];
        foreach ($this->protectedRoutes() as $route) {
            $declared = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'perm:') || str_starts_with($m, 'can:')));
            if (!$declared && !in_array($this->key($route), config('authz.allowlist'), true)) {
                $unmapped[] = $this->key($route);
            }
            $this->assertContains('authz.fence', $route->gatherMiddleware(), "{$this->key($route)} خارج الحارس");
        }

        $this->assertSame([], $unmapped, 'مسارات بلا صلاحية معلنة: '.implode('، ', $unmapped));
    }

    public function test_allowlist_has_no_stale_entries(): void
    {
        $keys = array_map(fn ($r) => $this->key($r), $this->protectedRoutes());

        $this->assertSame([], array_values(array_diff(config('authz.allowlist'), $keys)));
    }

    public function test_declared_permissions_exist_or_are_known_abilities(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $known = array_merge(Permission::pluck('slug')->all(), ['admin']);
        $missing = [];
        foreach ($this->protectedRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'perm:')) {
                    foreach (explode(',', substr($m, 5)) as $ability) {
                        if (!in_array($ability, $known, true)) {
                            $missing[$ability] = true;
                        }
                    }
                }
            }
        }

        $this->assertSame([], array_keys($missing), 'صلاحيات معلنة على مسارات وغير موجودة في جدول permissions');
    }

    public function test_enforce_mode_denies_and_log_mode_records_but_allows(): void
    {
        $sales = User::factory()->withPermissions(['clients.view'])->create();

        config(['authz.mode' => 'enforce']);
        $this->actingAs($sales)->get('/agents')->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'authz.denied', 'entity_label' => 'agents.index']);

        config(['authz.mode' => 'log']);
        $this->actingAs($sales)->get('/agents')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'authz.would_deny', 'entity_label' => 'agents.index']);
    }

    public function test_always_enforced_permissions_are_denied_even_in_log_mode(): void
    {
        config(['authz.mode' => 'log']);
        $sales = User::factory()->withPermissions(['invoices.view'])->create();

        $this->actingAs($sales)->post('/accounting/close-year')->assertForbidden();
        $this->actingAs($sales)->put('/settings', ['settings' => []])->assertForbidden();
        $this->actingAs($sales)->get('/flights/search')->assertForbidden();
    }

    public function test_inertia_requests_get_a_flash_message_instead_of_a_raw_403(): void
    {
        $sales = User::factory()->withPermissions(['clients.view'])->create();

        $this->actingAs($sales)->from('/clients')
            ->withHeaders(['X-Inertia' => 'true'])
            ->post('/accounting/close-year')
            ->assertRedirect('/clients')
            ->assertSessionHas('error', 'ليست لديك صلاحية لهذا الإجراء');
    }

    public function test_would_deny_is_recorded_once_an_hour_per_user_and_route(): void
    {
        config(['authz.mode' => 'log']);
        $sales = User::factory()->withPermissions(['clients.view'])->create();

        foreach (range(1, 5) as $_) {
            $this->actingAs($sales)->get('/agents');
        }

        $this->assertSame(1, AuditLog::where('action', 'authz.would_deny')->count());
    }

    public function test_report_groups_recorded_denials_by_route_and_role(): void
    {
        config(['authz.mode' => 'log']);
        $sales = User::factory()->withPermissions(['clients.view'])->create();
        $this->actingAs($sales)->get('/agents');

        $this->artisan('authz:report')
            ->expectsOutputToContain('agents.index')
            ->assertSuccessful();
    }
}
