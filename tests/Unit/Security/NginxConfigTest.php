<?php

namespace Tests\Unit\Security;

use Tests\TestCase;

/**
 * ترويسات nginx (E26.5): nginx لا يورّث add_header إلى location فيه add_header خاص، فكل كتلة كهذه تضمّن الملف
 * المشترك، وإلا سقطت الترويسات الست عن الملفات الثابتة و/build و/storage وعامل الخدمة. فحص نصي لـ docker/nginx.conf.
 */
class NginxConfigTest extends TestCase
{
    private const SNIPPET = 'include /etc/nginx/snippets/security-headers.conf;';

    private function conf(): string
    {
        return (string) file_get_contents(base_path('docker/nginx.conf'));
    }

    private function snippet(): string
    {
        return (string) file_get_contents(base_path('docker/nginx-security-headers.conf'));
    }

    /** بلا تعليقات */
    private function code(string $text): string
    {
        return (string) preg_replace('/#[^\n]*/', '', $text);
    }

    /**
     * كل كتلة location بنصها المباشر (بلا الكتل المتداخلة فيها) وكتلها المتداخلة.
     *
     * @return list<array{head: string, own: string, offset: int}>
     */
    private function locations(string $code, int $base = 0): array
    {
        $out = [];
        $offset = 0;
        while (preg_match('/location\s+([^{]+)\{/', $code, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $depth = 1;
            $i = $start;
            while ($depth > 0 && $i < strlen($code)) {
                $depth += $code[$i] === '{' ? 1 : ($code[$i] === '}' ? -1 : 0);
                $i++;
            }
            $body = substr($code, $start, $i - 1 - $start);
            $own = (string) preg_replace('/location\s+[^{]+\{(?:[^{}]|\{[^{}]*\})*\}/', '', $body);
            $out[] = ['head' => trim($m[1][0]), 'own' => $own, 'offset' => $base + $m[0][1]];
            array_push($out, ...$this->locations($body, $base + $start));
            $offset = $i;
        }

        return $out;
    }

    public function test_every_location_that_adds_a_header_includes_the_shared_headers(): void
    {
        $locations = $this->locations($this->code($this->conf()));
        $this->assertGreaterThanOrEqual(10, count($locations));

        $adding = array_filter($locations, fn ($l) => str_contains($l['own'], 'add_header'));
        $this->assertGreaterThanOrEqual(5, count($adding), 'firebase-sw والملفات الثابتة و/build و/storage وPDF');
        foreach ($adding as $location) {
            $this->assertStringContainsString(self::SNIPPET, $location['own'], "location {$location['head']}");
        }
    }

    public function test_the_server_level_uses_the_snippet_and_hides_the_version(): void
    {
        $server = $this->code($this->conf());
        $outside = (string) preg_replace('/location\s+[^{]+\{(?:[^{}]|\{[^{}]*\})*\}/', '', $server);

        $this->assertStringContainsString(self::SNIPPET, $outside);
        $this->assertStringNotContainsString('add_header', $outside, 'الترويسات العامة من الملف المشترك وحده');
        $this->assertMatchesRegularExpression('/^\s*server_tokens off;/m', $server);
        $this->assertStringNotContainsStringIgnoringCase('X-XSS-Protection', $this->code($this->conf()).$this->code($this->snippet()));
    }

    public function test_the_snippet_carries_the_six_headers_and_hsts_stays_on_this_host(): void
    {
        $snippet = $this->code($this->snippet());

        foreach (['X-Frame-Options "SAMEORIGIN"', 'X-Content-Type-Options "nosniff"', 'Referrer-Policy "strict-origin-when-cross-origin"',
            'Strict-Transport-Security "max-age=31536000"', 'Permissions-Policy "', 'Cross-Origin-Opener-Policy "same-origin"'] as $header) {
            $this->assertMatchesRegularExpression('/add_header '.preg_quote($header, '/').'[^;]* always;/', $snippet, $header);
        }
        $this->assertSame(6, substr_count($snippet, 'add_header'));
        $this->assertStringNotContainsString('includeSubDomains', $snippet);
        $this->assertStringNotContainsString('preload', $snippet);
        $this->assertStringContainsString('geolocation=(self)', $snippet);
        $this->assertStringContainsString('microphone=()', $snippet);
        $this->assertStringContainsString('camera=()', $snippet);
    }

    public function test_storage_is_sandboxed_before_the_static_block_except_pdf_templates(): void
    {
        $locations = collect($this->locations($this->code($this->conf())))->keyBy('head');
        $storage = $locations['^~ /storage/'] ?? null;
        $static = $locations->first(fn ($l) => str_starts_with($l['head'], '~* \.(js|css|png'));

        $this->assertNotNull($storage);
        $this->assertNotNull($static);
        $this->assertLessThan($static['offset'], $storage['offset'], '/storage قبل كتلة الملفات الثابتة');
        $this->assertMatchesRegularExpression("/add_header Content-Security-Policy \"default-src 'none';[^\"]*sandbox\" always;/", $storage['own']);
        $this->assertStringNotContainsString('immutable', $storage['own']);

        $pdf = $locations['~* \.pdf$'] ?? null;
        $this->assertNotNull($pdf, 'PDF كتلة متداخلة');
        $this->assertStringNotContainsString('sandbox', $pdf['own']);
        $this->assertStringContainsString('deny all;', $locations['~ /\.']['own'] ?? '', 'الملفات النقطية محجوبة داخل /storage أيضاً');
    }

    public function test_csp_reports_are_capped_at_the_edge_and_the_webhook_block_is_untouched(): void
    {
        $locations = collect($this->locations($this->code($this->conf())))->keyBy('head');

        $this->assertStringContainsString('client_max_body_size 16k;', $locations['= /csp-report']['own'] ?? '');
        $this->assertSame(16384, config('csp.report_max_bytes'));
        // E20: كتلة الويبهوك وkeepalive كما هي (WebhookContractDocTest)
        $this->assertStringContainsString('client_max_body_size 4m;', $locations['= /api/whatsapp/webhook']['own'] ?? '');
        $this->assertMatchesRegularExpression('/^\s*keepalive_timeout 100s;/m', $this->conf());
    }

    public function test_the_image_ships_the_snippet_where_the_config_includes_it(): void
    {
        $this->assertStringContainsString('COPY docker/nginx-security-headers.conf /etc/nginx/snippets/security-headers.conf', (string) file_get_contents(base_path('Dockerfile')));
    }
}
