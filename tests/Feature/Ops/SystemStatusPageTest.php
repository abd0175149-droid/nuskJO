<?php

namespace Tests\Feature\Ops;

use App\Models\User;
use App\Models\WaConversation;
use App\Services\Ops\SystemStatus;
use App\Services\WhatsApp\ChannelDoctor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\FakesMetaReads;
use Tests\TestCase;

/**
 * صفحة «حالة النظام» (E23): خلف ops.view والعلم ops_status_page، للقراءة فقط، بلا رمز ولا سر ولا رقم عميل.
 */
class SystemStatusPageTest extends TestCase
{
    use ConfiguresWhatsApp;
    use FakesMetaReads;
    use RefreshDatabase;

    private const SECTIONS = ['app', 'database', 'disk', 'queue', 'beats', 'backups', 'doctor', 'whatsapp', 'numbers', 'reverb'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.ops_status_page' => true, 'ops.meta_checks' => true, 'app.url' => self::OUR_URL]);
    }

    public function test_without_ops_view_every_route_is_forbidden(): void
    {
        $staff = User::factory()->withPermissions(['whatsapp.settings'], 'staff')->create();

        $this->actingAs($staff)->get('/ops/status')->assertForbidden();
        $this->actingAs($staff)->getJson('/api/ops/status')->assertForbidden();
        $this->actingAs($staff)->postJson('/api/ops/status/refresh')->assertForbidden();
    }

    public function test_the_flag_off_hides_the_page(): void
    {
        config(['features.env.ops_status_page' => false]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/ops/status')->assertNotFound();
        $this->actingAs($admin)->getJson('/api/ops/status')->assertNotFound();
    }

    public function test_the_admin_sees_every_section_and_no_secret_or_customer_number(): void
    {
        $this->configureChannel(['wa_app_id' => self::OUR_APP_ID, 'wa_token' => 'EAAG-status-secret-token', 'wa_app_secret' => 'status-app-secret-0001']);
        config(['services.whatsapp.waba_id' => '102290129340398']);
        WaConversation::factory()->windowOpen()->create(['phone' => '16505551234']);
        $this->fakeMetaReads();
        $admin = User::factory()->admin()->create();

        $page = $this->actingAs($admin)->get('/ops/status')->assertOk();
        $page->assertInertia(fn ($p) => $p->component('Ops/Status', false)->has('status.sections', count(self::SECTIONS)));

        $json = $this->actingAs($admin)->getJson('/api/ops/status')->assertOk()->json();
        foreach (self::SECTIONS as $section) {
            $this->assertArrayHasKey('level', $json['sections'][$section], $section);
        }
        $raw = json_encode($json).$page->getContent();
        foreach (['EAAG-status-secret-token', 'status-app-secret-0001', 'test-llm-key', '16505551234', (string) config('app.key')] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    public function test_sections_report_queue_backlog_and_the_last_doctor_run(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(20)->getTimestamp(), 'created_at' => now()->subMinutes(20)->getTimestamp()]);
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 60]);
        ChannelDoctor::store(ChannelDoctor::run(5, offline: true), alert: false);

        $s = SystemStatus::snapshot(fresh: true)['sections'];

        $this->assertSame('warn', $s['queue']['level']);
        $this->assertSame(1, $s['queue']['queues']['default']['available']);
        $this->assertGreaterThanOrEqual(1200, $s['queue']['queues']['default']['oldest_seconds']);
        $this->assertSame('error', $s['doctor']['level']);
        $this->assertContains('RETRY_AFTER', array_column($s['doctor']['problems'], 'code'));
    }

    public function test_the_snapshot_is_cached_and_refresh_rebuilds_it_with_a_limit(): void
    {
        $admin = User::factory()->admin()->create();
        $first = $this->actingAs($admin)->getJson('/api/ops/status')->json('at');
        $this->travel(10)->seconds();
        $this->assertSame($first, $this->actingAs($admin)->getJson('/api/ops/status')->json('at'));

        $this->assertNotSame($first, $this->actingAs($admin)->postJson('/api/ops/status/refresh')->assertOk()->json('at'));
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin)->postJson('/api/ops/status/refresh');
        }
        $this->actingAs($admin)->postJson('/api/ops/status/refresh')->assertStatus(429);
    }

    public function test_the_nav_link_follows_permission_and_flag(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/ops/status')->assertInertia(fn ($p) => $p->where('features.ops_status', true));

        config(['features.env.ops_status_page' => false]);
        $this->actingAs($admin)->get('/whatsapp/settings')->assertInertia(fn ($p) => $p->where('features.ops_status', false));
    }

    public function test_the_migration_grants_ops_view_to_the_admin_role_only_and_is_idempotent(): void
    {
        $migration = require database_path('migrations/2026_10_14_000023_e23_channel_diagnostics.php');
        $migration->up();
        $migration->up();

        $id = DB::table('permissions')->where('slug', 'ops.view')->value('id');
        $this->assertNotNull($id);
        $roles = DB::table('role_permissions')->where('permission_id', $id)->pluck('role_id')->all();
        $this->assertSame(DB::table('roles')->where('slug', 'admin')->pluck('id')->all(), $roles);
        $this->assertTrue(Schema::hasColumn('wa_admin_numbers', 'test_recipient'));
    }
}
