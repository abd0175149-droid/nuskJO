<?php

namespace Tests\Feature\Ops;

use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Ops\OpsMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * /internal/metrics (E24): مغلق بلا رمز، و404 لرمز خاطئ، وصيغة Prometheus من آخر لقطة وحدها، بلا بيانات عملاء
 * ولا جلسة، و429 بعد 30 طلباً في الدقيقة.
 */
class InternalMetricsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-metrics-token-0123456789abcdef0123456789';

    private function snapshotWithCustomerData(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create(['display_name' => 'عميل باسم ظاهر']);
        WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'body' => 'نص لا يخرج']);
        WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => 'failed', 'error_category' => 'window']);
        OpsMetrics::remember((new OpsMetrics)->snapshot());
    }

    public function test_without_a_configured_token_the_route_does_not_exist(): void
    {
        config(['ops.metrics_token' => null]);

        $this->get('/internal/metrics')->assertNotFound();
        $this->withToken('anything')->get('/internal/metrics')->assertNotFound();
    }

    public function test_a_wrong_or_missing_token_is_a_404_not_a_401(): void
    {
        config(['ops.metrics_token' => self::TOKEN]);

        $this->get('/internal/metrics')->assertNotFound();
        $this->withToken('wrong')->get('/internal/metrics')->assertNotFound();
        $this->withToken(self::TOKEN.'x')->get('/internal/metrics')->assertNotFound();
    }

    public function test_no_snapshot_yet_is_a_503(): void
    {
        config(['ops.metrics_token' => self::TOKEN]);

        $this->withToken(self::TOKEN)->get('/internal/metrics')->assertStatus(503)->assertSee('no snapshot');
    }

    public function test_the_right_token_reads_the_last_snapshot_without_customer_data_or_a_session(): void
    {
        config(['ops.metrics_token' => self::TOKEN]);
        $this->snapshotWithCustomerData();
        $sessions = DB::table('sessions')->count();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $res = $this->withToken(self::TOKEN)->get('/internal/metrics');
        $routeQueries = $queries;

        $res->assertOk();
        $this->assertStringStartsWith('text/plain; version=0.0.4', $res->headers->get('Content-Type'));
        $body = $res->getContent();
        $this->assertStringContainsString('nuskjo_queue_jobs{queue="default"} 0', $body);
        $this->assertStringContainsString('nuskjo_wa_outbound_today{source="bot"} 1', $body);
        $this->assertStringContainsString('nuskjo_wa_outbound_failed_1h{category="window"} 1', $body);
        $this->assertStringContainsString('# TYPE nuskjo_failed_jobs gauge', $body);
        foreach (WaConversation::pluck('phone') as $phone) {
            $this->assertStringNotContainsString((string) $phone, $body);
        }
        $this->assertStringNotContainsString('عميل باسم ظاهر', $body);
        $this->assertStringNotContainsString('نص لا يخرج', $body);
        $this->assertSame(0, $routeQueries, 'المسار يقرأ الكاش وحده');
        $this->assertNull($res->headers->get('Set-Cookie'));
        $this->assertSame($sessions, DB::table('sessions')->count());
    }

    public function test_the_thirty_first_request_in_a_minute_is_throttled(): void
    {
        config(['ops.metrics_token' => self::TOKEN]);
        $this->snapshotWithCustomerData();

        for ($i = 0; $i < 30; $i++) {
            $this->withToken(self::TOKEN)->get('/internal/metrics')->assertOk();
        }
        $this->withToken(self::TOKEN)->get('/internal/metrics')->assertStatus(429);
    }
}
