<?php

namespace Tests\Unit\Security;

use Tests\TestCase;

/**
 * لا سكربت ولا خط من طرف ثالث وقت التشغيل (E26.2): كل ما يحمّله المتصفح من /build على الأصل نفسه،
 * فتعمل سياسة أمن المحتوى بلا مضيف خارجي في script-src ولا font-src، ولا تُرسل عناوين الموظفين لمضيف آخر.
 * pdf.js من الحزمة (usePdfTemplate) لا من شبكة توزيع، والخطوط من @fontsource (E16.8).
 */
class NoThirdPartyRuntimeScriptsTest extends TestCase
{
    private const HOSTS = ['cdnjs.cloudflare.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'fonts.bunny.net', 'unpkg.com', 'jsdelivr.net', 'www.gstatic.com'];

    /** @return array<string, string> المسار النسبي => المحتوى */
    private function sources(string $dir, array $extensions): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (in_array($file->getExtension(), $extensions, true)) {
                $out[substr($file->getPathname(), strlen(base_path()) + 1)] = file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    private function frontEnd(): array
    {
        return $this->sources('resources/js', ['js', 'mjs', 'vue', 'ts'])
            + $this->sources('resources/css', ['css'])
            + $this->sources('resources/views', ['php']);
    }

    public function test_no_page_names_a_third_party_script_or_font_host(): void
    {
        $offenders = [];
        foreach ($this->frontEnd() as $path => $code) {
            foreach (self::HOSTS as $host) {
                if (str_contains($code, $host)) {
                    $offenders[] = "{$path}: {$host}";
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_scripts_are_never_injected_at_runtime(): void
    {
        $offenders = array_keys(array_filter(
            $this->sources('resources/js', ['js', 'mjs', 'vue', 'ts']),
            fn (string $code) => preg_match('/createElement\(\s*[\'"`]script[\'"`]\s*\)|window\.pdfjsLib/i', $code) === 1,
        ));

        $this->assertSame([], $offenders);
    }

    public function test_views_load_no_remote_script_stylesheet_or_css_import(): void
    {
        $offenders = [];
        foreach ($this->frontEnd() as $path => $code) {
            if (preg_match('#<script[^>]+src=["\']?(https?:)?//#i', $code)
                || preg_match('#<link[^>]+rel=["\']?(stylesheet|preload|modulepreload)[^>]+href=["\']?(https?:)?//#i', $code)
                || preg_match('#@import\s+(url\()?["\']?(https?:)?//#i', $code)) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_service_workers_import_nothing_from_another_origin(): void
    {
        foreach (glob(public_path('*.js')) as $worker) {
            $this->assertDoesNotMatchRegularExpression('#importScripts\(\s*[\'"](https?:)?//#', (string) file_get_contents($worker), basename($worker));
        }
    }

    public function test_pdf_templates_render_from_the_bundle_without_eval(): void
    {
        $composable = (string) file_get_contents(resource_path('js/composables/usePdfTemplate.js'));

        $this->assertStringContainsString("import('pdfjs-dist/legacy/build/pdf.mjs')", $composable);
        $this->assertMatchesRegularExpression('/isEvalSupported:\s*false/', $composable);
        $this->assertStringContainsString('pdf.worker.min.mjs?url', $composable);

        // الإصدار المثبّت يغلق CVE-2024-4367 (≥ 4.2.67)
        $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true);
        $version = $lock['packages']['node_modules/pdfjs-dist']['version'] ?? '0';
        $this->assertTrue(version_compare($version, '4.2.67', '>='), "pdfjs-dist {$version}");
    }
}
