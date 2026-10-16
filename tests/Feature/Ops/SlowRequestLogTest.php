<?php

namespace Tests\Feature\Ops;

use App\Logging\RedactSecrets;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

/**
 * سجل الطلبات البطيئة و5xx (E24): سطر واحد في قناة perf بقالب المسار، بلا رابط كامل ولا query ولا IP،
 * ولا شيء للطلب السريع الناجح.
 */
class SlowRequestLogTest extends TestCase
{
    use CapturesLogs;

    protected function setUp(): void
    {
        parent::setUp();
        Route::get('e24-test/items/{item}/slow', function () {
            usleep(120_000);

            return response('ok');
        })->name('e24.slow');
        Route::get('e24-test/items/{item}/fast', fn () => response('ok'));
        Route::get('e24-test/items/{item}/boom', fn () => throw new \RuntimeException('boom'));
        $this->captureLogs();
    }

    /** @return list<array> */
    private function perfLines(): array
    {
        return array_values(array_filter($this->logged, fn ($l) => $l['message'] === 'slow_or_5xx'));
    }

    public function test_a_slow_request_writes_one_line_with_the_route_template(): void
    {
        config(['ops.slow_ms' => 50]);

        $this->get('/e24-test/items/4242/slow?phone=16505551234&q=secret', ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();

        $lines = $this->perfLines();
        $this->assertCount(1, $lines);
        $ctx = $lines[0]['context'];
        $this->assertSame('warning', $lines[0]['level']);
        $this->assertSame('e24-test/items/{item}/slow', $ctx['route']);
        $this->assertSame('e24.slow', $ctx['name']);
        $this->assertSame('GET', $ctx['method']);
        $this->assertSame(200, $ctx['status']);
        $this->assertGreaterThan(50, $ctx['ms']);
        $this->assertArrayHasKey('request_id', $ctx);
        $encoded = json_encode($lines, JSON_UNESCAPED_UNICODE);
        foreach (['4242', '16505551234', 'secret', 'phone', '203.0.113.9'] as $leak) {
            $this->assertStringNotContainsString($leak, $encoded);
        }
    }

    public function test_a_fast_successful_request_writes_nothing(): void
    {
        $this->get('/e24-test/items/1/fast')->assertOk();

        $this->assertSame([], $this->perfLines());
    }

    public function test_a_server_error_writes_a_line_even_when_fast(): void
    {
        $this->get('/e24-test/items/7/boom')->assertStatus(500);

        $lines = $this->perfLines();
        $this->assertCount(1, $lines);
        $this->assertSame(500, $lines[0]['context']['status']);
        $this->assertSame('e24-test/items/{item}/boom', $lines[0]['context']['route']);
    }

    public function test_zero_disables_the_log_even_for_server_errors(): void
    {
        config(['ops.slow_ms' => 0]);
        $this->get('/e24-test/items/1/boom');
        $this->assertSame([], $this->perfLines());
    }

    public function test_the_health_route_is_left_to_its_own_hourly_line(): void
    {
        config(['ops.health_checks' => true]);

        $this->get('/up')->assertStatus(500);

        $this->assertSame([], $this->perfLines());
    }

    public function test_the_perf_channel_is_a_daily_warning_log_with_redaction(): void
    {
        $channel = config('logging.channels.perf');

        $this->assertSame('daily', $channel['driver']);
        $this->assertSame('warning', $channel['level']);
        $this->assertSame(14, $channel['days']);
        $this->assertStringEndsWith('logs/perf.log', $channel['path']);
        $this->assertContains(RedactSecrets::class, $channel['tap']);
    }
}
