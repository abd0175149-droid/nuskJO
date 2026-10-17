<?php

namespace Tests\Feature\Security;

use App\Services\Security\CspReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * POST /csp-report (E26.4): عام بلا جلسة ولا CSRF، يرد 204 دائماً، ويحفظ التوجيه ومصدر المحجوب وقالب المسار فقط.
 * الرابط الخام لا يُحفظ (قد يحمل ?search= برقم عميل)، والجسم الكبير 413، والحادي والثلاثون في الدقيقة 429.
 */
class CspReportTest extends TestCase
{
    use RefreshDatabase;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        // مخزن file حقيقي في مجلد مؤقت: نفتّش ملفاته بحثاً عن الرقم
        $this->cacheDir = sys_get_temp_dir().'/csp-cache-'.bin2hex(random_bytes(4));
        config(['cache.stores.csp_test' => ['driver' => 'file', 'path' => $this->cacheDir, 'lock_path' => $this->cacheDir],
            'csp.report_store' => 'csp_test']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->cacheDir);
        }
        parent::tearDown();
    }

    private function report(array $body, string $type = 'application/csp-report')
    {
        return $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => $type], json_encode($body));
    }

    private function cacheContents(): string
    {
        $all = '';
        if (is_dir($this->cacheDir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                $all .= file_get_contents($f->getPathname());
            }
        }

        return $all;
    }

    public function test_a_report_is_counted_by_directive_source_and_path_template_only(): void
    {
        $this->report(['csp-report' => [
            'document-uri' => 'https://nuskjo.example.test/whatsapp/inbox?search=16505551234#conv',
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example/x.js?token=abc',
            'original-policy' => "default-src 'self'",
            'script-sample' => 'alert(16505551234)',
        ]])->assertNoContent();
        $this->report(['csp-report' => [
            'document-uri' => 'https://nuskjo.example.test/whatsapp/inbox?search=16505553333',
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example/other.js',
        ]])->assertNoContent();

        $rows = CspReports::summary()['rows'];
        $this->assertSame([['directive' => 'script-src-elem', 'source' => 'https://evil.example', 'path' => '/whatsapp/inbox', 'count' => 2]], $rows);

        $stored = $this->cacheContents();
        $this->assertNotSame('', $stored);
        foreach (['16505551234', '16505553333', 'search=', 'token=abc', 'alert(', 'original'] as $absent) {
            $this->assertStringNotContainsString($absent, $stored);
        }
    }

    public function test_reporting_api_batches_and_keywords_are_understood(): void
    {
        $this->report([
            ['type' => 'csp-violation', 'url' => 'https://nuskjo.example.test/clients/15/edit', 'body' => [
                'documentURL' => 'https://nuskjo.example.test/clients/15/edit', 'effectiveDirective' => 'script-src-elem', 'blockedURL' => 'inline',
            ]],
            ['type' => 'csp-violation', 'body' => [
                'documentURL' => 'https://nuskjo.example.test/invoices/9/print', 'effectiveDirective' => 'img-src', 'blockedURL' => 'data:image/png;base64,AAAA',
            ]],
            ['type' => 'deprecation', 'body' => ['id' => 'x']],
        ], 'application/reports+json')->assertNoContent();

        $rows = collect(CspReports::summary()['rows'])->keyBy('path');
        $this->assertSame(['script-src-elem', 'inline'], [$rows['/clients/{id}/edit']['directive'], $rows['/clients/{id}/edit']['source']]);
        $this->assertSame('data', $rows['/invoices/{id}/print']['source']);
        $this->assertCount(2, $rows);
    }

    public function test_the_endpoint_writes_no_session_needs_no_csrf_and_sets_no_cookie(): void
    {
        $response = $this->report(['csp-report' => ['document-uri' => 'https://nuskjo.example.test/', 'effective-directive' => 'img-src', 'blocked-uri' => 'https://cdn.example/a.png']]);

        $response->assertNoContent();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_oversized_bodies_are_refused_and_unknown_types_ignored(): void
    {
        $this->report(['csp-report' => ['document-uri' => 'https://nuskjo.example.test/', 'blocked-uri' => str_repeat('a', 17000)]])->assertStatus(413);

        $this->report(['csp-report' => ['document-uri' => 'https://nuskjo.example.test/', 'effective-directive' => 'img-src']], 'text/plain')->assertNoContent();
        $this->assertSame([], CspReports::summary()['rows']);
    }

    public function test_the_thirty_first_report_in_a_minute_from_one_address_is_throttled(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->report(['csp-report' => ['document-uri' => 'https://nuskjo.example.test/', 'effective-directive' => 'img-src']])->assertNoContent();
        }

        $this->report(['csp-report' => ['document-uri' => 'https://nuskjo.example.test/', 'effective-directive' => 'img-src']])->assertStatus(429);
    }

    public function test_distinct_patterns_are_capped_per_day(): void
    {
        config(['csp.report_max_distinct' => 2]);
        foreach (['a', 'b', 'c', 'd'] as $page) {
            $this->report(['csp-report' => ['document-uri' => "https://nuskjo.example.test/{$page}", 'effective-directive' => 'img-src']]);
        }

        $summary = CspReports::summary();
        $this->assertCount(2, $summary['rows']);
        $this->assertSame(2, $summary['other']);
    }

    public function test_the_command_lists_patterns_by_count(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->report(['csp-report' => ['document-uri' => 'https://nuskjo.example.test/offers', 'effective-directive' => 'style-src-elem', 'blocked-uri' => 'chrome-extension://abc/x.css']]);
        }
        $this->report(['csp-report' => ['document-uri' => 'https://nuskjo.example.test/', 'effective-directive' => 'img-src', 'blocked-uri' => 'https://ads.example/p.gif']]);

        $this->assertSame(0, Artisan::call('security:csp-report', ['--days' => 7]));
        $out = Artisan::output();
        $this->assertMatchesRegularExpression('/chrome-extension:\/\/abc.*\/offers.*style-src-elem.*3/u', $out);
        $this->assertLessThan(strpos($out, 'ads.example'), strpos($out, 'chrome-extension'));
        $this->assertStringContainsString('تقارير فقط', $out);
    }
}
