<?php

namespace Tests\Unit\Arch;

use Tests\TestCase;

/**
 * حارس معماري (2026-11-06): واتساب عبر بوابة OpenWA الخاصة بنُسك وحدها (docker/openwa)، وكل نداء لها يمر بـ
 * OpenWa\OpenWaClient: عنوانها ومفتاح X-API-Key لا يظهران في أي ملف آخر. لا حزمة واتساب في composer ولا npm:
 * المحرك داخل حاوية البوابة، ونُسك يتحدث معها HTTP فقط. ومفتاح Gemini لا يُكتب في رابط.
 */
class GatewayOnlyTest extends TestCase
{
    private const CLIENT = 'app/Services/WhatsApp/OpenWa/OpenWaClient.php';

    /** @return array<string, string> المسار النسبي => المحتوى بلا تعليقات */
    private function appFiles(): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));
                $out[$path] = $this->withoutComments((string) file_get_contents($file->getPathname()));
            }
        }

        return $out;
    }

    private function withoutComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= is_array($token) ? $token[1] : $token;
            }
        }

        return $out;
    }

    private function filesMatching(string $pattern): array
    {
        return array_keys(array_filter($this->appFiles(), fn (string $code) => preg_match($pattern, $code) === 1));
    }

    public function test_the_gateway_api_key_header_is_sent_only_by_the_client(): void
    {
        $this->assertSame([self::CLIENT], $this->filesMatching('/[\'"]X-API-Key[\'"]/'));
    }

    public function test_the_gateway_url_is_read_only_by_the_client(): void
    {
        $this->assertSame([self::CLIENT], $this->filesMatching('/whatsapp\.openwa\.url|nuskjo-openwa:2785/'));
    }

    public function test_no_graph_api_call_remains(): void
    {
        $this->assertSame([], $this->filesMatching('/graph\.facebook\.com/'));
    }

    public function test_gemini_host_appears_only_in_its_provider(): void
    {
        $this->assertSame(['app/Services/WhatsApp/Llm/GeminiProvider.php'], $this->filesMatching('/generativelanguage\.googleapis\.com/'));
    }

    public function test_no_api_key_in_a_url_or_query_array(): void
    {
        $this->assertSame([], $this->filesMatching('/\?key=|[\'"]key[\'"]\s*=>\s*\$apiKey/'));
    }

    public function test_no_whatsapp_library_is_installed_in_the_app(): void
    {
        $manifests = file_get_contents(base_path('composer.json')).file_get_contents(base_path('package.json'));

        foreach (['whatsapp-web.js', 'baileys', '@whiskeysockets', 'venom-bot', 'wppconnect', 'open-wa'] as $library) {
            $this->assertStringNotContainsStringIgnoringCase($library, $manifests);
        }
    }

    public function test_the_gateway_image_is_pinned_and_patched(): void
    {
        $dockerfile = (string) file_get_contents(base_path('docker/openwa/Dockerfile'));
        $this->assertMatchesRegularExpression('/ARG OPENWA_VERSION=\d+\.\d+\.\d+/', $dockerfile);
        $this->assertStringContainsString('apply-receipts.cjs', $dockerfile);
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));
        foreach (['SEND_PACING_ENABLED: "false"', 'SIMULATE_TYPING: "false"', 'MAX_CONCURRENT_SESSIONS: "0"', 'SSRF_ALLOWED_HOSTS: nuskjo-app'] as $line) {
            $this->assertStringContainsString($line, $compose);
        }
    }
}
