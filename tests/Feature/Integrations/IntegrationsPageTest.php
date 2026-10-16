<?php

namespace Tests\Feature\Integrations;

use App\Enums\AuditAction;
use App\Jobs\DeliverIntegrationWebhook;
use App\Models\AuditLog;
use App\Models\IntegrationEndpoint;
use App\Models\IntegrationOutboxRow;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Integrations\IntegrationOutbox;
use App\Services\Integrations\WebhookSigner;
use App\Services\Security\StepUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsIntegrations;
use Tests\TestCase;

/**
 * صفحة التكاملات (E40.7): كل المسارات 403 بلا الصلاحية و404 والعلم مطفأ، والسر يظهر مرة واحدة في رد الإنشاء
 * والتدوير ثم لا يظهر في أي رد أو props أو سجل، والإنشاء والتدوير بلا تحقق معزّز يُعادان إلى التأكيد، وكل كتابة
 * لها صف تدقيق. ping ينجح فيتيح التشغيل، والإعادة للفاشل وحده، ورموز MCP تُعرض مرة وتُبطل.
 */
class IntegrationsPageTest extends TestCase
{
    use BuildsIntegrations;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableOutbox();
        config(['features.env.mcp_server' => true, 'integrations.self_hosts' => ['nusk.example.org']]);
        $this->fakeDns();
        $this->admin = User::factory()->admin()->create();
    }

    private function create(array $overrides = []): array
    {
        return $this->actingAs($this->admin)->postJson('/settings/integrations/endpoints', $overrides + [
            'name' => 'n8n الإنتاج', 'url' => 'https://hooks.example.com/webhook/abc', 'events' => ['invoice.approved'], 'include_amounts' => false,
        ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json();
    }

    public function test_every_route_is_forbidden_without_the_permission(): void
    {
        $endpoint = $this->endpoint();
        $row = IntegrationOutboxRow::forceCreate(['endpoint_id' => $endpoint->id, 'event' => 'ping', 'idempotency_key' => 'k', 'payload' => [], 'status' => 'dead']);
        $token = PersonalAccessToken::forceCreate(['tokenable_type' => User::class, 'tokenable_id' => $this->admin->id, 'name' => 't', 'token' => str_repeat('a', 64), 'abilities' => ['mcp:read']]);
        $sales = User::factory()->withPermissions(['invoices.view'], 'sales')->create();

        $this->actingAs($sales);
        $this->get('/settings/integrations')->assertForbidden();
        $this->postJson('/settings/integrations/endpoints', [])->assertForbidden();
        $this->putJson("/settings/integrations/endpoints/{$endpoint->id}", [])->assertForbidden();
        $this->deleteJson("/settings/integrations/endpoints/{$endpoint->id}")->assertForbidden();
        $this->postJson("/settings/integrations/endpoints/{$endpoint->id}/rotate")->assertForbidden();
        $this->postJson("/settings/integrations/endpoints/{$endpoint->id}/ping")->assertForbidden();
        $this->postJson("/settings/integrations/endpoints/{$endpoint->id}/toggle", ['active' => false])->assertForbidden();
        $this->getJson('/api/integrations/deliveries')->assertForbidden();
        $this->postJson("/api/integrations/deliveries/{$row->id}/redrive")->assertForbidden();
        $this->postJson('/settings/integrations/mcp-tokens', ['name' => 'x', 'days' => 7])->assertForbidden();
        $this->deleteJson("/settings/integrations/mcp-tokens/{$token->id}")->assertForbidden();

        // صاحب رموز MCP وحده لا يدير الوجهات
        $this->actingAs(User::factory()->withPermissions(['mcp.use'], 'mcp-only')->create());
        $this->get('/settings/integrations')->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.manage', false)->where('endpoints', []));
        $this->postJson('/settings/integrations/endpoints', [])->assertForbidden();
        $this->assertSame(1, IntegrationEndpoint::count());
    }

    public function test_the_flags_off_hide_the_page_and_its_routes(): void
    {
        config(['features.env.integration_outbox' => false, 'features.env.mcp_server' => false]);
        $endpoint = $this->endpoint();
        $this->actingAs($this->admin);

        $this->get('/settings/integrations')->assertNotFound();
        $this->postJson('/settings/integrations/endpoints', ['name' => 'x', 'url' => 'https://hooks.example.com/x', 'events' => ['invoice.approved']])->assertNotFound();
        $this->postJson("/settings/integrations/endpoints/{$endpoint->id}/ping")->assertNotFound();
        $this->postJson('/settings/integrations/mcp-tokens', ['name' => 'x', 'days' => 7])->assertNotFound();
    }

    public function test_creating_shows_the_secret_once_then_never_again_and_is_audited_without_it(): void
    {
        $created = $this->create();
        $secret = $created['secret'];
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertSame('hooks.example.com', $created['endpoint']['host']);
        $this->assertFalse($created['endpoint']['is_active'], 'موقوفة حتى ping ناجح');
        $this->assertArrayNotHasKey('url', $created['endpoint']);

        $endpoint = IntegrationEndpoint::sole();
        $this->assertSame($secret, $endpoint->secret);
        $this->assertNotSame($secret, \DB::table('integration_endpoints')->value('secret'), 'مشفّر في القاعدة');

        $page = $this->get('/settings/integrations')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Settings/Integrations', false)
            ->has('endpoints', 1)->where('endpoints.0.events', ['invoice.approved'])->has('catalog', 3));
        $this->assertStringNotContainsString($secret, $page->getContent());
        $this->assertStringNotContainsString('/webhook/abc', $page->getContent(), 'المضيف وحده');
        $this->assertStringNotContainsString($secret, $this->putJson("/settings/integrations/endpoints/{$endpoint->id}", ['name' => 'جديد', 'url' => 'https://hooks.example.com/webhook/abc', 'events' => ['invoice.approved']])->getContent());
        $this->assertStringNotContainsString($secret, $this->getJson('/api/integrations/deliveries')->getContent());

        $audit = AuditLog::where('action', AuditAction::IntegrationEndpointCreate->value)->sole();
        $this->assertSame(['host' => 'hooks.example.com', 'events' => ['invoice.approved'], 'include_amounts' => false], $audit->changes);
        $this->assertStringNotContainsString($secret, (string) json_encode(AuditLog::pluck('changes')));
        $this->assertNull(session('integration_secret'));
    }

    public function test_the_guard_refuses_bad_urls_with_an_arabic_message(): void
    {
        $this->fakeDns(['internal.example.com' => ['10.0.0.5']]);
        $this->actingAs($this->admin);

        $this->postJson('/settings/integrations/endpoints', ['name' => 'x', 'url' => 'http://hooks.example.com/x', 'events' => ['invoice.approved']])
            ->assertJsonValidationErrors(['url' => 'https://']);
        $this->postJson('/settings/integrations/endpoints', ['name' => 'x', 'url' => 'https://internal.example.com/x', 'events' => ['invoice.approved']])
            ->assertJsonValidationErrors(['url' => 'شبكة خاصة']);
        $this->postJson('/settings/integrations/endpoints', ['name' => 'x', 'url' => 'https://hooks.example.com/x', 'events' => ['message.received']])
            ->assertJsonValidationErrors('events.0');
        $this->assertSame(0, IntegrationEndpoint::count());
    }

    public function test_sensitive_writes_without_a_fresh_step_up_are_bounced_to_confirmation(): void
    {
        config(['features.env.step_up' => true]);
        $endpoint = $this->endpoint();
        $this->actingAs($this->admin)->withSession([StepUp::SESSION_KEY => now()->subMinutes(16)->getTimestamp()]);

        $this->postJson('/settings/integrations/endpoints', ['name' => 'x', 'url' => 'https://hooks.example.com/x', 'events' => ['invoice.approved']])
            ->assertStatus(423)->assertJson(['code' => 'STEP_UP_REQUIRED']);
        $this->postJson("/settings/integrations/endpoints/{$endpoint->id}/rotate")->assertStatus(423);
        $this->deleteJson("/settings/integrations/endpoints/{$endpoint->id}")->assertStatus(423);
        $this->putJson("/settings/integrations/endpoints/{$endpoint->id}", ['name' => 'x', 'url' => 'https://evil.example.com/x', 'events' => ['invoice.approved']])->assertStatus(423);
        $this->postJson('/settings/integrations/mcp-tokens', ['name' => 'x', 'days' => 7])->assertStatus(423);
        // الإيقاف حماية لا تُعاق
        $this->postJson("/settings/integrations/endpoints/{$endpoint->id}/toggle", ['active' => false])->assertOk();

        $this->assertSame(1, IntegrationEndpoint::count());
        $this->assertSame('https://hooks.example.com/webhook/nusk', $endpoint->fresh()->url);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_rotation_keeps_the_old_secret_for_24_hours_and_shows_the_new_one_once(): void
    {
        $created = $this->create();
        $endpoint = IntegrationEndpoint::sole();

        $rotated = $this->postJson("/settings/integrations/endpoints/{$endpoint->id}/rotate")->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();

        $endpoint->refresh();
        $this->assertNotSame($created['secret'], $rotated['secret']);
        $this->assertSame($rotated['secret'], $endpoint->secret);
        $this->assertSame([$rotated['secret'], $created['secret']], $endpoint->signingSecrets());
        $this->assertNotNull($rotated['endpoint']['previous_secret_until']);
        $this->assertSame(1, AuditLog::where('action', AuditAction::IntegrationEndpointRotate->value)->count());
        $this->assertStringNotContainsString($rotated['secret'], (string) json_encode(AuditLog::pluck('changes')));
    }

    public function test_ping_then_activate_and_a_url_change_requires_a_new_ping(): void
    {
        $this->create();
        $endpoint = IntegrationEndpoint::sole();

        $this->postJson("/settings/integrations/endpoints/{$endpoint->id}/toggle", ['active' => true])->assertJsonValidationErrors('active');

        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
        $this->postJson("/settings/integrations/endpoints/{$endpoint->id}/ping")->assertOk()->assertJson(['ok' => true, 'status' => 200]);
        Http::assertSent(fn (ClientRequest $r) => $r->header('X-Nusk-Event')[0] === 'ping' && WebhookSigner::verify(
            $r->header('webhook-id')[0], (int) $r->header('webhook-timestamp')[0], $r->body(), $r->header('webhook-signature')[0], $endpoint->fresh()->secret));
        $this->assertSame('delivered', IntegrationOutboxRow::where('event', 'ping')->value('status'));

        $this->postJson("/settings/integrations/endpoints/{$endpoint->id}/toggle", ['active' => true])->assertOk()->assertJsonPath('endpoint.is_active', true);
        $this->assertTrue($endpoint->fresh()->is_active);

        $this->putJson("/settings/integrations/endpoints/{$endpoint->id}", ['name' => 'n8n', 'url' => 'https://other.example.com/hook', 'events' => ['invoice.approved', 'quote_request.created']])
            ->assertOk()->assertJsonPath('endpoint.is_active', false)->assertJsonPath('endpoint.host', 'other.example.com');
        $this->postJson("/settings/integrations/endpoints/{$endpoint->id}/toggle", ['active' => true])->assertJsonValidationErrors('active');

        $actions = AuditLog::whereIn('action', [AuditAction::IntegrationEndpointToggle->value, AuditAction::IntegrationEndpointUpdate->value])->pluck('action')->all();
        $this->assertSame(['integ.endpoint_toggle', 'integ.endpoint_update'], $actions);
    }

    public function test_a_failed_ping_reports_the_status_and_does_not_count_as_a_failure(): void
    {
        $endpoint = $this->endpoint(['is_active' => false]);
        Http::fake(['hooks.example.com/*' => Http::response('', 401)]);
        $this->actingAs($this->admin);

        $this->postJson("/settings/integrations/endpoints/{$endpoint->id}/ping")->assertOk()->assertJson(['ok' => false, 'status' => 401, 'error' => 'http_401']);

        $this->assertNull($endpoint->fresh()->last_ping_ok_at);
        $this->assertSame(0, $endpoint->fresh()->consecutive_failures);
    }

    public function test_deleting_with_pending_rows_needs_an_explicit_drop(): void
    {
        $endpoint = $this->endpoint();
        Queue::fake();
        IntegrationOutbox::record('invoice.approved', 'k1', ['invoice_number' => 'INV-1']);
        $this->actingAs($this->admin);

        $this->deleteJson("/settings/integrations/endpoints/{$endpoint->id}")->assertStatus(409)->assertJson(['pending' => 1]);
        $this->deleteJson("/settings/integrations/endpoints/{$endpoint->id}", ['drop_pending' => true])->assertOk();

        $this->assertSame(0, IntegrationEndpoint::count());
        $this->assertSame(0, IntegrationOutboxRow::count());
        $this->assertSame(['dropped_pending' => 1, 'rows' => 1], array_intersect_key(AuditLog::where('action', AuditAction::IntegrationEndpointDelete->value)->sole()->changes, ['dropped_pending' => 0, 'rows' => 0]));
    }

    public function test_deliveries_list_filters_and_redrive_only_failed_or_pending(): void
    {
        Queue::fake();
        $endpoint = $this->endpoint();
        [$dead] = IntegrationOutbox::record('invoice.approved', 'k-dead', ['invoice_number' => 'INV-1', 'phone' => '16505551234']);
        [$done] = IntegrationOutbox::record('invoice.approved', 'k-done', ['invoice_number' => 'INV-2']);
        IntegrationOutboxRow::whereKey($dead)->update(['status' => 'dead', 'attempts' => 6, 'last_error' => 'http_500']);
        IntegrationOutboxRow::whereKey($done)->update(['status' => 'delivered']);
        $this->actingAs($this->admin);

        $list = $this->getJson('/api/integrations/deliveries?status=dead')->assertOk()->json();
        $this->assertSame(1, $list['meta']['total']);
        $this->assertSame(['invoice_number' => 'INV-1'], $list['data'][0]['payload']['data']);
        $this->assertArrayNotHasKey('headers', $list['data'][0]);

        $this->postJson("/api/integrations/deliveries/{$done}/redrive")->assertStatus(422);
        $this->postJson("/api/integrations/deliveries/{$dead}/redrive")->assertOk();
        $row = IntegrationOutboxRow::find($dead);
        $this->assertSame(['pending', 0], [$row->status, $row->attempts]);
        Queue::assertPushed(DeliverIntegrationWebhook::class, fn ($job) => $job->rowId === $dead);
        $this->assertSame(1, AuditLog::where('action', AuditAction::IntegrationDeliveryRedrive->value)->count());

        $endpoint->forceFill(['is_active' => false])->save();
        IntegrationOutboxRow::whereKey($dead)->update(['status' => 'dead']);
        $this->postJson("/api/integrations/deliveries/{$dead}/redrive")->assertStatus(422);
    }

    public function test_mcp_tokens_are_shown_once_capped_at_30_days_listed_and_revoked(): void
    {
        $this->actingAs($this->admin);

        $this->postJson('/settings/integrations/mcp-tokens', ['name' => 'Claude Code', 'days' => 31])->assertJsonValidationErrors('days');
        $created = $this->postJson('/settings/integrations/mcp-tokens', ['name' => 'Claude Code', 'days' => 7])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertMatchesRegularExpression('/^\d+\|njmcp_[A-Za-z0-9]{48}$/', $created['token']);

        $token = PersonalAccessToken::sole();
        $this->assertSame(['mcp:read'], $token->abilities);
        $this->assertSame(7, (int) round(now()->diffInDays($token->expires_at)));
        $this->assertNotSame(explode('|', $created['token'])[1], $token->token, 'البصمة وحدها مخزّنة');

        $page = $this->get('/settings/integrations')->assertInertia(fn (Assert $p) => $p->has('mcp.tokens', 1)->where('mcp.tokens.0.name', 'Claude Code'));
        $this->assertStringNotContainsString(explode('|', $created['token'])[1], $page->getContent());

        $other = User::factory()->withPermissions(['mcp.use'], 'mcp-user')->create();
        $this->actingAs($other)->deleteJson("/settings/integrations/mcp-tokens/{$token->id}")->assertNotFound();
        $this->actingAs($this->admin)->deleteJson("/settings/integrations/mcp-tokens/{$token->id}")->assertOk();
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame(['mcp.token_create', 'mcp.token_revoke'], AuditLog::whereIn('action', ['mcp.token_create', 'mcp.token_revoke'])->orderBy('id')->pluck('action')->all());
        $this->assertStringNotContainsString('njmcp_', (string) json_encode(AuditLog::pluck('changes')));
    }

    public function test_the_admin_only_flags_cannot_be_toggled_by_a_settings_manager(): void
    {
        $manager = User::factory()->withPermissions(['whatsapp.settings'], 'wa-manager')->create();
        config(['features.env.integration_outbox' => null]);

        $this->actingAs($manager)->patchJson('/api/whatsapp/settings/flags/integration_outbox', ['state' => 'on', 'reason' => 'تجربة الربط'])->assertForbidden();
        $this->actingAs($manager)->patchJson('/api/whatsapp/settings/flags/mcp_server', ['state' => 'on', 'reason' => 'تجربة الخادم'])->assertForbidden();
    }
}
