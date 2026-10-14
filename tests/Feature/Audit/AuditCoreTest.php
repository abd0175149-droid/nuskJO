<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use App\Support\AuditActor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

/**
 * نواة سجل التدقيق (E09): للإلحاق فقط، والفاعل من السياق، والكتابة الصارمة والآمنة والعيّنات، والتقليم.
 */
class AuditCoreTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    public function test_the_migration_is_idempotent_and_the_table_is_append_only(): void
    {
        (require database_path('migrations/2026_10_14_000010_extend_audit_logs.php'))->up();

        $entityId = collect(Schema::getColumns('audit_logs'))->firstWhere('name', 'entity_id');
        $this->assertTrue($entityId['nullable']);
        foreach (['severity', 'actor_type', 'actor_ref', 'method', 'path', 'user_agent', 'request_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('audit_logs', $column), $column);
        }

        $row = AuditLog::log(AuditAction::Approve, 'invoice', 1, 'INV-1');
        $this->expectExceptionMessage('append-only');
        DB::table('audit_logs')->where('id', $row->id)->update(['action' => 'delete']);
    }

    public function test_the_model_refuses_updates_and_deletes(): void
    {
        $row = AuditLog::log(AuditAction::Approve, 'invoice', 1);

        foreach ([fn () => $row->update(['entity_label' => 'x']), fn () => $row->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('عُدّل صف التدقيق');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_only_admins_get_the_audit_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(['audit.export', 'audit.view'], Permission::where('module', 'audit')->orderBy('slug')->pluck('slug')->all());
        $this->assertTrue(User::factory()->admin()->create()->can('audit.view'));
        $this->assertFalse(User::factory()->withPermissions(['invoices.view'])->create()->can('audit.view'));
    }

    public function test_a_row_carries_actor_request_and_default_severity(): void
    {
        $user = User::factory()->create();
        Route::get('/_audit_probe', fn () => AuditLog::log(AuditAction::UserRoleChange, 'User', 7, 'x')->id)->middleware('web');
        Context::add('request_id', 'req-abcdef12');

        $id = $this->actingAs($user)->withHeader('User-Agent', 'probe/1.0')->get('/_audit_probe')->getContent();

        $row = AuditLog::find($id);
        $this->assertSame('user', $row->actor_type);
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($user->name, $row->user_name);
        $this->assertSame('warn', $row->severity);
        $this->assertSame('GET', $row->method);
        $this->assertSame('_audit_probe', $row->path);
        $this->assertSame('probe/1.0', $row->user_agent);
        $this->assertNotNull($row->request_id);
    }

    public function test_outside_a_request_the_actor_is_the_system_without_an_ip(): void
    {
        $row = AuditLog::log(AuditAction::SystemReset, 'system');

        $this->assertSame('system', $row->actor_type);
        $this->assertNull($row->ip_address);
        $this->assertNull($row->user_id);
        $this->assertSame('error', $row->severity);
    }

    public function test_the_actor_scope_ends_with_its_callback(): void
    {
        $inside = AuditActor::as('bot', 'conv:9', fn () => AuditLog::log(AuditAction::Create, 'quote_request', 1));
        $after = AuditLog::log(AuditAction::Create, 'quote_request', 2);
        $admin = User::factory()->create();
        $mirrored = AuditActor::as('admin_number', '4', fn () => AuditLog::log(AuditAction::Delete, 'wa_knowledge_entry', 3), $admin->id);

        $this->assertSame(['bot', 'conv:9', null], [$inside->actor_type, $inside->actor_ref, $inside->user_id]);
        $this->assertSame('system', $after->actor_type);
        $this->assertSame(['admin_number', '4', $admin->id, $admin->name], [$mirrored->actor_type, $mirrored->actor_ref, $mirrored->user_id, $mirrored->user_name]);
    }

    public function test_safe_writes_non_money_events_only_when_extended(): void
    {
        config(['audit.extended' => false]);
        $this->assertNull(AuditLog::safe(AuditAction::WaBotToggle, 'wa_conversation', 1));
        $this->assertNotNull(AuditLog::safe(AuditAction::Approve, 'invoice', 1));

        config(['audit.extended' => true]);
        $this->assertNotNull(AuditLog::safe(AuditAction::WaBotToggle, 'wa_conversation', 1));
    }

    public function test_safe_never_throws_and_logs_the_failure(): void
    {
        $this->captureLogs();
        Schema::drop('audit_logs');

        $this->assertNull(AuditLog::safe(AuditAction::Approve, 'invoice', 1));
        $this->assertLogged('error', 'audit.write_failed', ['action' => 'approve', 'class' => QueryException::class]);
    }

    public function test_sampling_limits_rows_and_reports_what_it_suppressed(): void
    {
        config(['audit.extended' => true]);

        for ($i = 0; $i < 25; $i++) {
            AuditLog::sampled(AuditAction::AuthLoginFailed, 'a@b.c|1.2.3.4', 'user');
        }
        $this->assertSame(10, AuditLog::count());

        $this->travel(1)->minutes();
        $next = AuditLog::sampled(AuditAction::AuthLoginFailed, 'a@b.c|1.2.3.4', 'user');
        $this->assertSame(15, $next->changes['suppressed_count']);
    }

    public function test_the_global_hourly_cap_holds_when_keys_vary(): void
    {
        config(['audit.extended' => true, 'audit.sample_global_per_hour' => 30]);

        for ($i = 0; $i < 50; $i++) {
            AuditLog::sampled(AuditAction::AccessDenied, "ip-{$i}", 'route');
        }

        $this->assertSame(30, AuditLog::count());
    }

    public function test_pruning_removes_old_noise_and_keeps_money(): void
    {
        $old = now()->subDays(91);
        DB::table('audit_logs')->insert([
            ['action' => 'auth.login_failed', 'entity_type' => 'user', 'created_at' => $old, 'severity' => 'info', 'actor_type' => 'user'],
            ['action' => 'approve', 'entity_type' => 'invoice', 'created_at' => now()->subDays(400), 'severity' => 'info', 'actor_type' => 'user'],
            ['action' => 'auth.login_failed', 'entity_type' => 'user', 'created_at' => now()->subDays(10), 'severity' => 'info', 'actor_type' => 'user'],
        ]);

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

        $this->assertSame(['approve', 'auth.login_failed'], AuditLog::orderBy('action')->pluck('action')->all());
    }

    public function test_a_forbidden_page_is_sampled_once_per_user_and_route(): void
    {
        config(['audit.extended' => true]);
        $user = User::factory()->withPermissions(['whatsapp.view'])->create();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)->get('/whatsapp/settings')->assertForbidden();
        }

        $denied = AuditLog::whereIn('action', ['access.denied', 'authz.denied', 'authz.would_deny'])->get();
        $this->assertGreaterThan(0, $denied->count());
        $this->assertLessThanOrEqual(10, $denied->count());
    }

    public function test_every_action_fits_the_column_and_has_a_label_and_category(): void
    {
        foreach (AuditAction::cases() as $action) {
            $this->assertLessThanOrEqual(30, strlen($action->value), $action->name);
            $this->assertNotSame('', $action->label());
            $this->assertContains($action->category(), ['money', 'access', 'config', 'whatsapp', 'agent', 'meta', 'noise', 'system']);
            $this->assertInstanceOf(AuditSeverity::class, $action->severity());
        }
        $this->assertSame(count(AuditAction::cases()), count(array_unique(array_map(fn ($a) => $a->value, AuditAction::cases()))));
    }
}
