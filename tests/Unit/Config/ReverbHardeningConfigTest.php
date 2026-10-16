<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * تحصين Reverb والنشر (E28): الأصل بالمضيف وحده من APP_URL، وسقف اتصالات، وحد رسائل، ولا أحداث من المتصفح،
 * والنشر من الخادم داخلي بمهلة قصيرة، وواجهة /apps مغلقة، وReverb على loopback.
 */
class ReverbHardeningConfigTest extends TestCase
{
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            $this->setEnv($key, $value);
        }
        parent::tearDown();
    }

    private function setEnv(string $key, ?string $value): void
    {
        $this->saved += [$key => $_ENV[$key] ?? null];
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    /** يقرأ ملف الإعداد من جديد بالبيئة المعطاة */
    private function load(string $file, array $env): array
    {
        foreach ($env as $key => $value) {
            $this->setEnv($key, $value);
        }

        return require base_path("config/{$file}.php");
    }

    public function test_origins_default_to_the_app_host_and_never_contain_a_wildcard(): void
    {
        $app = $this->load('reverb', ['APP_URL' => 'https://nuskjo.grade.sbs', 'REVERB_ALLOWED_ORIGINS' => null])['apps']['apps'][0];

        $this->assertSame(['nuskjo.grade.sbs'], $app['allowed_origins']);
        $this->assertSame('none', $app['accept_client_events_from']);
        $this->assertTrue($app['rate_limiting']['enabled']);
        $this->assertSame(60, $app['rate_limiting']['max_attempts']);
        $this->assertTrue($app['rate_limiting']['terminate_on_limit']);
        $this->assertIsInt($app['max_connections']);
        $this->assertSame(200, $app['max_connections']);
    }

    public function test_origins_can_be_listed_or_reopened_for_a_rollback(): void
    {
        $listed = $this->load('reverb', ['REVERB_ALLOWED_ORIGINS' => 'nuskjo.grade.sbs, staff.example.test'])['apps']['apps'][0];
        $this->assertSame(['nuskjo.grade.sbs', 'staff.example.test'], $listed['allowed_origins']);

        $open = $this->load('reverb', ['REVERB_ALLOWED_ORIGINS' => '*'])['apps']['apps'][0];
        $this->assertSame(['*'], $open['allowed_origins']);
    }

    public function test_server_publishing_is_internal_with_a_short_timeout(): void
    {
        $reverb = $this->load('broadcasting', [
            'REVERB_HOST' => 'nuskjo.grade.sbs', 'REVERB_PORT' => '443', 'REVERB_SCHEME' => 'https',
            'REVERB_PUBLISH_HOST' => '127.0.0.1', 'REVERB_PUBLISH_PORT' => '8080', 'REVERB_PUBLISH_SCHEME' => 'http',
        ])['connections']['reverb'];

        $this->assertSame('127.0.0.1', $reverb['options']['host']);
        $this->assertSame('8080', (string) $reverb['options']['port']);
        $this->assertSame('http', $reverb['options']['scheme']);
        $this->assertFalse($reverb['options']['useTLS']);
        $this->assertLessThanOrEqual(2, $reverb['client_options']['timeout']);
        $this->assertLessThanOrEqual(1, $reverb['client_options']['connect_timeout']);
    }

    public function test_without_publish_values_the_public_host_is_kept(): void
    {
        $reverb = $this->load('broadcasting', [
            'REVERB_HOST' => 'nuskjo.grade.sbs', 'REVERB_PUBLISH_HOST' => null, 'REVERB_PUBLISH_PORT' => null, 'REVERB_PUBLISH_SCHEME' => null,
        ])['connections']['reverb'];

        $this->assertSame('nuskjo.grade.sbs', $reverb['options']['host']);
        $this->assertTrue($reverb['options']['useTLS']);
        $this->assertGreaterThan(0, $reverb['client_options']['timeout']);
    }

    public function test_the_container_publishes_internally_and_closes_the_http_api(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));
        $this->assertStringContainsString('REVERB_PUBLISH_HOST=${REVERB_PUBLISH_HOST:-127.0.0.1}', $compose);
        $this->assertStringContainsString('REVERB_PUBLISH_PORT=${REVERB_PUBLISH_PORT:-8080}', $compose);

        $supervisor = file_get_contents(base_path('docker/supervisord.conf'));
        $this->assertMatchesRegularExpression('/reverb:start --host=127\.0\.0\.1 --port=8080/', $supervisor);
        $this->assertStringNotContainsString('reverb:start --host=0.0.0.0', $supervisor);

        $nginx = file_get_contents(base_path('docker/nginx.conf'));
        $this->assertMatchesRegularExpression('/location \^~ \/apps \{\s*return 404;\s*\}/', $nginx);
        $this->assertMatchesRegularExpression('/location \^~ \/app\/ \{[^}]*proxy_pass http:\/\/127\.0\.0\.1:8080;/', $nginx);
        $this->assertDoesNotMatchRegularExpression('/location \/apps \{[^}]*proxy_pass/', $nginx);
    }
}
