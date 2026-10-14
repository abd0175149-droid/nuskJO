<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * فحص Docker يعتمد /up (لا / الذي ينجح بالتحويل إلى صفحة الدخول)، بمهلة إقلاع تغطي أول نبضة،
 * و/up بلا سطر في سجل nginx، والعمّال والمجدول لا يدخلون FATAL بعد ثلاث محاولات سريعة.
 */
class DockerHealthcheckTest extends TestCase
{
    public function test_compose_probes_up_with_a_start_period(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));
        $this->assertSame(1, preg_match('/healthcheck:\n(?:\s+#.*\n)*\s+test: (\[.*\])\n((?:\s+\w+: .+\n)+)/', $compose, $m));

        $this->assertSame(['CMD', 'curl', '-fsS', '-o', '/dev/null', '-H', 'Accept: application/json', 'http://localhost:4020/up'], json_decode($m[1], true));
        // مفتاح مكرر في YAML يُفشل docker compose كله
        $this->assertSame(1, substr_count($m[2], 'start_period:'));
        $this->assertStringContainsString('start_period: 120s', $m[2]);
    }

    public function test_the_image_has_the_same_probe(): void
    {
        $this->assertMatchesRegularExpression('#^HEALTHCHECK .*--start-period=120s .*curl -fsS -o /dev/null http://localhost:4020/up#m', file_get_contents(base_path('Dockerfile')));
    }

    public function test_up_is_not_written_to_the_access_log(): void
    {
        $this->assertMatchesRegularExpression('#location = /up \{\s+access_log off;#', file_get_contents(base_path('docker/nginx.conf')));
    }

    public function test_workers_and_scheduler_keep_retrying_on_start(): void
    {
        $ini = parse_ini_file(base_path('docker/supervisord.conf'), true, INI_SCANNER_RAW);

        foreach (['laravel-queue', 'laravel-wa', 'laravel-schedule'] as $program) {
            $this->assertSame('10', $ini["program:{$program}"]['startretries'], $program);
            $this->assertSame('5', $ini["program:{$program}"]['startsecs'], $program);
        }
    }
}
