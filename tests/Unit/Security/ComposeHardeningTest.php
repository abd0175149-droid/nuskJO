<?php

namespace Tests\Unit\Security;

use Tests\TestCase;

/**
 * تقوية الحاوية (E26.8): no-new-privileges وحدود الذاكرة والعمليات والمعالج في compose، وصور الأساس مثبّتة بالبصمة،
 * وإصدار كروميوم مسجّل، وsupervisorctl متاح لاختبار الدخان. symfony/yaml غير مثبّتة، فالفحص على كتلة الخدمة نصاً
 * (والملف نفسه يُتحقق منه بـ docker compose config).
 */
class ComposeHardeningTest extends TestCase
{
    /** أسطر خدمة nuskjo-app حتى الخدمة التالية */
    private function appService(): string
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));
        $this->assertSame(1, preg_match('/^  nuskjo-app:\n(.*?)(?=^  \S|^\S)/ms', $compose, $m));

        return $m[1];
    }

    public function test_the_app_service_runs_without_new_privileges_and_with_resource_limits(): void
    {
        $service = $this->appService();

        $this->assertMatchesRegularExpression('/^    security_opt:\n      - "no-new-privileges:true"$/m', $service);
        foreach (['mem_limit', 'memswap_limit', 'pids_limit', 'cpus'] as $key) {
            $this->assertSame(1, preg_match_all('/^    '.$key.': \S+$/m', $service), "{$key} مرة واحدة");
        }
        $this->assertMatchesRegularExpression('/^    pids_limit: \$\{NUSKJO_PIDS_LIMIT:-1024\}$/m', $service);
        $this->assertMatchesRegularExpression('/^    mem_limit: \$\{NUSKJO_MEM_LIMIT:-(\d+)g\}$/m', $service);
        preg_match('/^    mem_limit: \$\{NUSKJO_MEM_LIMIT:-(\d+)g\}$/m', $service, $mem);
        $this->assertGreaterThanOrEqual(2, (int) $mem[1], 'لا تقل عن 2g');
        $this->assertStringContainsString('memswap_limit: ${NUSKJO_MEM_LIMIT:-'.$mem[1].'g}', $service, 'بلا مبادلة فوق الحد');
        // المؤجل عمداً موثّق ولا يُفعَّل خلسة
        $this->assertDoesNotMatchRegularExpression('/^    (read_only|cap_drop):/m', $service);
    }

    public function test_csp_and_storage_modes_ship_in_their_safe_defaults(): void
    {
        $service = $this->appService();

        $this->assertStringContainsString('- CSP_MODE=${CSP_MODE:-report}', $service);
        $this->assertStringContainsString('- NUSKJO_STORAGE_PERMS=${NUSKJO_STORAGE_PERMS:-legacy}', $service);
    }

    public function test_every_base_image_is_pinned_by_digest(): void
    {
        foreach (['Dockerfile', 'Dockerfile.test'] as $file) {
            $text = (string) file_get_contents(base_path($file));
            preg_match_all('/^FROM\s+(\S+)/m', $text, $from);
            preg_match_all('/^COPY\s+--from=(\S+)/m', $text, $copies);
            $images = [...$from[1], ...array_filter($copies[1], fn ($ref) => str_contains($ref, ':'))];

            $this->assertNotEmpty($images, $file);
            foreach ($images as $image) {
                $this->assertMatchesRegularExpression('/^[a-z0-9.\/_-]+:[\w.-]+@sha256:[0-9a-f]{64}$/', $image, "{$file}: {$image}");
            }
        }
        $this->assertMatchesRegularExpression('/COPY --from=composer:2@sha256:[0-9a-f]{64} /', (string) file_get_contents(base_path('Dockerfile')));
    }

    public function test_the_image_records_chromium_and_lets_nginx_read_storage_through_the_group(): void
    {
        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

        $this->assertMatchesRegularExpression('/^RUN addgroup nginx www-data\b/m', $dockerfile);
        $this->assertStringContainsString('> /etc/chromium-version', $dockerfile);
        $this->assertDoesNotMatchRegularExpression('/chmod -R 7[0-7]5\b/', $dockerfile, 'لا قراءة للغير على storage في الصورة');
    }

    public function test_supervisorctl_can_reach_supervisord_for_the_smoke_test(): void
    {
        $ini = parse_ini_file(base_path('docker/supervisord.conf'), true, INI_SCANNER_RAW);

        $this->assertSame('unix:///var/run/supervisor.sock', $ini['supervisorctl']['serverurl'] ?? null);
        $this->assertSame('/var/run/supervisor.sock', $ini['unix_http_server']['file'] ?? null);
        $this->assertSame('0700', $ini['unix_http_server']['chmod'] ?? null, 'لـ root وحده');
        $this->assertArrayHasKey('rpcinterface:supervisor', $ini);
    }

    public function test_deploy_runs_the_smoke_test_after_the_container_is_healthy(): void
    {
        $deploy = (string) file_get_contents(base_path('scripts/deploy.sh'));
        $smoke = (string) file_get_contents(base_path('scripts/smoke.sh'));

        $this->assertGreaterThan(strpos($deploy, 'NOT healthy after deploy'), strpos($deploy, 'bash scripts/smoke.sh'));
        foreach (['supervisorctl status', 'ops:smoke-card', '/etc/chromium-version', 'storage/app/wa-media', '/storage/__smoke__.png', 'BASE/up'] as $step) {
            $this->assertStringContainsString($step, $smoke);
        }
    }
}
