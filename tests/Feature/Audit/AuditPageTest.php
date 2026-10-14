<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaAdminAction;
use App\Models\WaAdminNumber;
use App\Support\AuditActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * صفحة سجل التدقيق (E09.11): خلف العلم والصلاحية، قراءة فقط، والتصدير مدقَّق ومحمي من حقن الصيغ ومسقوف.
 */
class AuditPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.page' => true]);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_page_does_not_exist_while_the_flag_is_off(): void
    {
        config(['audit.page' => false]);

        $this->actingAs($this->admin)->get('/audit-logs')->assertNotFound();
        $this->actingAs($this->admin)->get('/audit-logs/export')->assertNotFound();
    }

    public function test_staff_without_the_permission_are_refused(): void
    {
        $sales = User::factory()->withPermissions(['invoices.view'])->create();

        $this->actingAs($sales)->get('/audit-logs')->assertForbidden();
        $this->actingAs($sales)->getJson('/api/audit-logs/entity?type=offer&id=1')->assertForbidden();
        $viewer = User::factory()->withPermissions(['audit.view'], 'auditor')->create();
        $this->actingAs($viewer)->get('/audit-logs/export')->assertForbidden();
    }

    public function test_the_list_filters_and_labels_actors(): void
    {
        $number = WaAdminNumber::factory()->create(['name' => 'رقم المدير']);
        AuditLog::log(AuditAction::Approve, 'invoice', 7, 'INV-7', ['total' => 10]);
        AuditActor::as('bot', 'conv:3', fn () => AuditLog::log(AuditAction::Create, 'quote_request', 1));
        AuditActor::as('admin_number', (string) $number->id, fn () => AuditLog::log(AuditAction::Delete, 'wa_knowledge_entry', 2));
        AuditLog::log(AuditAction::SystemReset, 'system', null, null, null, AuditSeverity::Error);

        $this->actingAs($this->admin)->get('/audit-logs')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('AuditLogs/Index', false)
            ->has('logs.data', 4)
            ->where('logs.data.0.action_label', 'مسح بيانات النظام')
            ->where('logs.data.1.actor_label', '👔 رقم إدارة: رقم المدير')
            ->where('logs.data.2.actor_label', '🤖 البوت')
            ->where('canExport', true));

        $this->actingAs($this->admin)->get('/audit-logs?'.http_build_query(['action' => ['approve'], 'entity_type' => 'invoice']))
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.entity_id', 7));
        $this->actingAs($this->admin)->get('/audit-logs?severity=error')
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));
        $this->actingAs($this->admin)->get('/audit-logs?action[]=nonsense')->assertSessionHasErrors('action.0');
    }

    public function test_details_and_entity_history_return_full_changes(): void
    {
        $row = AuditLog::log(AuditAction::OfferPriceChange, 'offer', 5, 'عمرة', ['changed' => ['يبدأ من' => ['before' => 400, 'after' => 450]]]);
        AuditLog::log(AuditAction::Update, 'offer', 5, 'عمرة');
        AuditLog::log(AuditAction::Update, 'offer', 6, 'أخرى');

        $this->actingAs($this->admin)->getJson("/api/audit-logs/{$row->id}")->assertOk()
            ->assertJsonPath('changes.changed.يبدأ من.after', 450);
        $this->actingAs($this->admin)->getJson('/api/audit-logs/entity?type=offer&id=5')->assertOk()->assertJsonCount(2);
    }

    public function test_the_admin_tab_lists_admin_number_actions(): void
    {
        $number = WaAdminNumber::factory()->create(['name' => 'رقم المدير']);
        WaAdminAction::create(['admin_number_id' => $number->id, 'tool' => 'set_customer_bot', 'action' => 'off', 'status' => 'done', 'summary' => 'أُوقف', 'created_at' => now()]);

        $this->actingAs($this->admin)->get('/audit-logs?tab=admin')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'admin')->where('logs', null)
            ->has('adminActions.data', 1)->where('adminActions.data.0.admin', 'رقم المدير'));
    }

    public function test_export_is_audited_first_escapes_formulas_and_is_capped(): void
    {
        config(['audit.export_max_rows' => 2]);
        AuditLog::log(AuditAction::Create, 'offer', 1, 'الأقدم يُقتطع');
        AuditLog::log(AuditAction::Update, 'offer', 1, 'عادي');
        AuditLog::log(AuditAction::Update, 'offer', 1, '=HYPERLINK("http://x","y")');

        $response = $this->actingAs($this->admin)->get('/audit-logs/export?entity_type=offer');
        $csv = $response->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_values(array_filter(explode("\n", $csv)));
        $this->assertCount(1 + 2 + 1, $lines, 'الترويسة وصفّان وسطر الاقتطاع');
        $this->assertStringContainsString('اقتُطع التصدير', end($lines));
        $this->assertStringNotContainsString('الأقدم يُقتطع', $csv, 'الأحدث أولاً');
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringContainsString(",\"'=HYPERLINK", $csv);

        $export = AuditLog::where('action', AuditAction::AuditExport->value)->sole();
        $this->assertSame(['entity_type' => 'offer'], $export->changes['filters']);
        $this->assertSame('warn', $export->severity);
    }

    public function test_every_audit_route_is_read_only(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'audit-logs'));

        $this->assertCount(4, $routes);
        foreach ($routes as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods(), $route->uri());
        }
    }
}
