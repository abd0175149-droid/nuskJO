<?php

namespace Tests\Unit\Arch;

use Tests\TestCase;

/**
 * حارس معماري: القناة الوحيدة هي WhatsApp Cloud API الرسمية، وكل نداء لميتا يمر بعميل Graph في WhatsAppChannel،
 * ومفتاح Gemini لا يُكتب في رابط، وأرقام الإصدارات القديمة في جدول GraphVersions وحده.
 */
class OfficialChannelOnlyTest extends TestCase
{
    /** @return array<string, string> المسار النسبي => المحتوى */
    private function appFiles(): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $out[substr($file->getPathname(), strlen(base_path()) + 1)] = file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    private function filesContaining(string $needle): array
    {
        return array_keys(array_filter($this->appFiles(), fn (string $code) => str_contains($code, $needle)));
    }

    public function test_graph_is_called_only_from_the_channel(): void
    {
        $this->assertSame(['app/Services/WhatsApp/WhatsAppChannel.php'], $this->filesContaining('graph.facebook.com'));
    }

    public function test_gemini_host_appears_only_in_its_provider(): void
    {
        $this->assertSame(['app/Services/WhatsApp/Llm/GeminiProvider.php'], $this->filesContaining('generativelanguage.googleapis.com'));
    }

    public function test_no_api_key_in_a_url_or_query_array(): void
    {
        $offenders = array_keys(array_filter($this->appFiles(),
            fn (string $code) => preg_match('/\?key=|[\'"]key[\'"]\s*=>\s*\$apiKey/', $code) === 1));

        $this->assertSame([], $offenders);
    }

    public function test_old_graph_versions_live_only_in_the_table(): void
    {
        $offenders = array_keys(array_filter($this->appFiles(), fn (string $code) => preg_match('/[\'"]v2[0-4]\.0[\'"]/', $code) === 1));

        $this->assertSame(['app/Services/WhatsApp/GraphVersions.php'], $offenders);
    }

    public function test_no_unofficial_whatsapp_library_is_installed(): void
    {
        $manifests = file_get_contents(base_path('composer.json')).file_get_contents(base_path('package.json'));

        foreach (['whatsapp-web.js', 'baileys', '@whiskeysockets', 'venom-bot', 'wppconnect', 'open-wa'] as $library) {
            $this->assertStringNotContainsStringIgnoringCase($library, $manifests);
        }
    }
}
