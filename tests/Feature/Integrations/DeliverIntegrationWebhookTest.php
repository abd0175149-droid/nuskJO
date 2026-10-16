<?php

namespace Tests\Feature\Integrations;

use App\Enums\AuditAction;
use App\Jobs\DeliverIntegrationWebhook;
use App\Models\AuditLog;
use App\Models\IntegrationEndpoint;
use App\Models\IntegrationOutboxRow;
use App\Models\Notification;
use App\Models\User;
use App\Services\Integrations\IntegrationOutbox;
use App\Services\Integrations\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsIntegrations;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

/**
 * تسليم الصندوق الصادر (E40.5): توقيع Standard Webhooks على البايتات المرسلة نفسها، وعنوان مثبّت ضد DNS rebinding،
 * بلا تحويلات، والنتيجة: 2xx مسلَّم، و410 يوقف الوجهة، و4xx dead بإشعار، والعابر بالانتظار (Retry-After أولاً)،
 * و6 محاولات ثم dead، و20 فشلاً متتالياً إيقاف آلي. والسر لا يظهر في last_error ولا في السجل.
 */
class DeliverIntegrationWebhookTest extends TestCase
{
    use BuildsIntegrations;
    use CapturesLogs;
    use RefreshDatabase;

    /** @var list<array{request: Request, options: array}> */
    private array $sent = [];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableOutbox();
        $this->fakeDns();
        $this->admin = User::factory()->admin()->create();
        config(['integrations.self_hosts' => ['nusk.example.org']]);
    }

    /** @param  list<mixed>  $responses  رد Http أو Throwable، بالترتيب */
    private function receiver(array $responses): void
    {
        Http::fake(function (Request $request, array $options) use (&$responses) {
            $this->sent[] = ['request' => $request, 'options' => $options];
            $next = array_shift($responses) ?? Http::response('', 200);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });
    }

    private function row(?IntegrationEndpoint $endpoint = null, string $key = 'invoice_7_approved_1760000000'): IntegrationOutboxRow
    {
        Queue::fake();
        $endpoint ??= $this->endpoint();
        [$id] = IntegrationOutbox::record('invoice.approved', $key, ['invoice_number' => 'INV-7', 'approved_at' => '2026-10-16T10:00:00+03:00'], $endpoint);

        return IntegrationOutboxRow::findOrFail($id);
    }

    private function deliver(IntegrationOutboxRow $row): IntegrationOutboxRow
    {
        app()->call([new DeliverIntegrationWebhook($row->id), 'handle']);

        return $row->fresh();
    }

    public function test_a_2xx_delivers_signed_bytes_to_the_pinned_address_without_redirects(): void
    {
        $this->receiver([Http::response('ok', 204)]);
        $endpoint = $this->endpoint(['consecutive_failures' => 3]);
        $row = $this->deliver($this->row($endpoint));

        $this->assertSame('delivered', $row->status);
        $this->assertSame(204, $row->last_status_code);
        $this->assertNotNull($row->delivered_at);
        $this->assertSame(0, $endpoint->fresh()->consecutive_failures);
        $this->assertNotNull($endpoint->fresh()->last_success_at);

        $this->assertCount(1, $this->sent);
        ['request' => $request, 'options' => $options] = $this->sent[0];
        $this->assertSame('https://hooks.example.com/webhook/nusk', $request->url());
        $this->assertSame(['hooks.example.com:443:'.self::PUBLIC_IP], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(10, (int) $options['timeout']);
        $this->assertSame(5, (int) $options['connect_timeout']);

        $this->assertSame('invoice_7_approved_1760000000', $request->header('webhook-id')[0]);
        $this->assertSame($row->last_delivery_id, $request->header('X-Nusk-Delivery-Id')[0]);
        $this->assertSame('invoice.approved', $request->header('X-Nusk-Event')[0]);
        $this->assertTrue(WebhookSigner::verify('invoice_7_approved_1760000000', (int) $request->header('webhook-timestamp')[0], $request->body(),
            $request->header('webhook-signature')[0], $endpoint->fresh()->secret));
        $body = json_decode($request->body(), true);
        $this->assertSame(['type', 'id', 'timestamp', 'schema', 'data'], array_keys($body));
        $this->assertSame(['invoice_number' => 'INV-7', 'approved_at' => '2026-10-16T10:00:00+03:00'], $body['data']);
    }

    public function test_the_address_checked_is_the_address_used_even_if_dns_changes_afterwards(): void
    {
        $dns = $this->fakeDns();
        $this->receiver([Http::response('', 200)]);
        $row = $this->row();
        $dns->map['hooks.example.com'] = ['10.0.0.5'];   // بعد الحفظ تغيّر الـ DNS: الحارس يرى الجديد عند التسليم

        $row = $this->deliver($row);

        $this->assertSame([], $this->sent, 'لا طلب إلى عنوان صار خاصاً');
        $this->assertSame('dead', $row->status);
        $this->assertSame('ssrf_rejected:private_address', $row->last_error);
        $this->assertFalse($row->endpoint->is_active);

        // وعنوان عام: الطلب يحمل العنوان الذي فُحص لحظتها وحده، والتحليل مرة واحدة لكل تسليم
        $dns->map['hooks.example.com'] = ['93.184.216.35'];
        $dns->asked = [];
        $endpoint = $this->endpoint(['url' => 'https://hooks.example.com/second']);
        $this->deliver($this->row($endpoint, 'k2'));
        $this->assertSame(['hooks.example.com:443:93.184.216.35'], $this->sent[0]['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertSame(['hooks.example.com'], $dns->asked);
    }

    public function test_a_redirect_is_not_followed_and_counts_as_a_failure(): void
    {
        $this->receiver([Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data'])]);
        $row = $this->deliver($this->row());

        $this->assertCount(1, $this->sent);
        $this->assertSame('dead', $row->status);
        $this->assertSame('redirect_302', $row->last_error);
        $this->assertSame(1, $row->endpoint->consecutive_failures);
    }

    public function test_transient_failures_back_off_30_then_120_seconds_and_retry_after_wins(): void
    {
        $this->freezeTime();
        $this->receiver([Http::response('', 503), Http::response('', 503), Http::response('', 429, ['Retry-After' => '50'])]);
        $row = $this->deliver($this->row());

        $this->assertSame('pending', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertSame(now()->addSeconds(30)->getTimestamp(), $row->next_attempt_at->getTimestamp());
        Queue::assertPushed(DeliverIntegrationWebhook::class, fn ($job) => $job->rowId === $row->id && $job->delay !== null);

        $this->travel(30)->seconds();
        $row = $this->deliver($row);
        $this->assertSame(2, $row->attempts);
        $this->assertSame(now()->addSeconds(120)->getTimestamp(), $row->next_attempt_at->getTimestamp());

        $this->travel(120)->seconds();
        $row = $this->deliver($row);
        $this->assertSame(now()->addSeconds(50)->getTimestamp(), $row->next_attempt_at->getTimestamp(), 'Retry-After');
        $this->assertSame('http_429', $row->last_error);
    }

    public function test_a_job_before_its_time_or_for_a_claimed_row_sends_nothing(): void
    {
        $this->receiver([Http::response('', 503)]);
        $row = $this->deliver($this->row());
        $this->assertCount(1, $this->sent);

        $this->deliver($row);   // موعده لم يحل
        $this->assertCount(1, $this->sent);

        $other = $this->row(key: 'k-claimed');
        $other->forceFill(['status' => 'delivering'])->save();   // عامل آخر استولى عليه
        $this->deliver($other);
        $this->deliver($other);
        $this->assertCount(1, $this->sent, 'وظيفتان للصف نفسه: طلب واحد فقط');
    }

    public function test_six_attempts_then_dead(): void
    {
        $this->receiver(array_fill(0, 6, Http::response('', 500)));
        $row = $this->row();
        for ($i = 0; $i < 6; $i++) {
            $row->forceFill(['next_attempt_at' => now()->subSecond()])->save();
            $row = $this->deliver($row);
        }

        $this->assertSame(6, $row->attempts);
        $this->assertSame('dead', $row->status);
        $this->assertCount(6, $this->sent);
    }

    public function test_a_4xx_is_dead_at_once_with_one_alert_a_day(): void
    {
        $this->receiver([Http::response('', 400), Http::response('', 422)]);
        $endpoint = $this->endpoint();
        $first = $this->deliver($this->row($endpoint));
        $second = $this->deliver($this->row($endpoint, 'k2'));

        $this->assertSame(['dead', 'dead'], [$first->status, $second->status]);
        $this->assertSame(1, $first->attempts);
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->count(), 'إشعار واحد في اليوم للوجهة');
        $this->assertTrue($endpoint->fresh()->is_active);
    }

    public function test_410_pauses_the_endpoint(): void
    {
        $this->receiver([Http::response('', 410)]);
        $row = $this->deliver($this->row());

        $this->assertSame('dead', $row->status);
        $endpoint = $row->endpoint;
        $this->assertFalse($endpoint->is_active);
        $this->assertStringContainsString('410', $endpoint->paused_reason);
        $this->assertSame(1, AuditLog::where('action', AuditAction::IntegrationEndpointAutoPaused->value)->count());
    }

    public function test_twenty_consecutive_failures_pause_the_endpoint_with_an_audit_row_and_one_alert(): void
    {
        $this->receiver(array_fill(0, 3, Http::response('', 500)));
        $endpoint = $this->endpoint(['consecutive_failures' => 18]);
        $this->deliver($this->row($endpoint, 'a'));
        $this->assertTrue($endpoint->fresh()->is_active);
        $this->deliver($this->row($endpoint, 'b'));

        $endpoint->refresh();
        $this->assertFalse($endpoint->is_active);
        $this->assertSame(20, $endpoint->consecutive_failures);
        $this->assertStringContainsString('20', $endpoint->paused_reason);
        $audit = AuditLog::where('action', AuditAction::IntegrationEndpointAutoPaused->value)->sole();
        $this->assertSame('hooks.example.com', $audit->changes['host']);
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->count());

        // وجهة موقوفة: لا محاولة، والصف يعود معلّقاً
        $row = $this->row($endpoint, 'c');
        $row = $this->deliver($row);
        $this->assertCount(2, $this->sent);
        $this->assertSame('pending', $row->status);
        $this->assertSame(0, $row->attempts);
        $this->assertTrue($row->next_attempt_at->isFuture());
    }

    public function test_the_secret_never_reaches_last_error_or_the_log(): void
    {
        $this->captureLogs();
        $endpoint = $this->endpoint(['url' => 'https://hooks.example.com/webhook?token=abc123secretvalue']);
        $secret = $endpoint->secret;
        $this->receiver([new ConnectionException("cURL error 28 for https://hooks.example.com/webhook?token=abc123secretvalue key {$secret}")]);

        $row = $this->deliver($this->row($endpoint));

        $this->assertSame('pending', $row->status);
        $this->assertStringStartsWith('connection_failed: ConnectionException', $row->last_error);
        $this->assertStringNotContainsString($secret, $row->last_error);
        $this->assertStringNotContainsString('abc123secretvalue', $row->last_error);
        $this->assertStringContainsString('[hooks.example.com]', $row->last_error);
        $logs = json_encode($this->logged, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($secret, $logs);
        $this->assertStringNotContainsString('abc123secretvalue', $logs);
        $this->assertStringContainsString('integrations.delivery_failed', $logs);
    }

    public function test_rotation_signs_with_both_secrets_for_24_hours_then_one(): void
    {
        $this->receiver([Http::response('', 200), Http::response('', 200)]);
        $old = WebhookSigner::generateSecret();
        $endpoint = $this->endpoint(['previous_secret' => $old, 'previous_secret_expires_at' => now()->addHours(24)]);

        $this->deliver($this->row($endpoint, 'a'));
        $this->assertCount(2, explode(' ', $this->sent[0]['request']->header('webhook-signature')[0]));

        $this->travel(25)->hours();
        $this->deliver($this->row($endpoint, 'b'));
        $this->assertCount(1, explode(' ', $this->sent[1]['request']->header('webhook-signature')[0]));
    }

    public function test_with_the_flag_off_the_worker_idles_and_the_row_waits(): void
    {
        $this->receiver([]);
        $row = $this->row();
        config(['features.env.integration_outbox' => false]);

        $row = $this->deliver($row);

        $this->assertSame([], $this->sent);
        $this->assertSame('pending', $row->status);
        $this->assertSame(0, $row->attempts);
    }
}
