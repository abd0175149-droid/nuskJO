<?php

namespace Tests\Unit\Arch;

use Tests\TestCase;

/**
 * حارس معماري: القناة الوحيدة هي WhatsApp Cloud API الرسمية، وكل نداء لميتا يمر بـ Graph\GraphClient (E18)،
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

    public function test_graph_is_called_only_from_the_client(): void
    {
        $this->assertSame(['app/Services/WhatsApp/Graph/GraphClient.php'], $this->filesContaining('graph.facebook.com'));
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

    /** الكود بلا تعليقات (ذكر النمط في تعليق لا يُحسب) */
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

    /** يرسل الكود رمز وصول بنفسه: withToken، أو ترويسة Authorization/Bearer، أو access_token، أو رابط Graph مع Http مباشرة */
    private function sendsTokenItself(string $code): bool
    {
        $code = $this->withoutComments($code);

        return preg_match('/(->|::)withToken\s*\(|[\'"]Authorization[\'"]\s*=>|[\'"]Bearer\s|[\'"]access_token[\'"]\s*=>/i', $code) === 1
            || (str_contains($code, 'GraphClient::url(') && str_contains($code, 'Http::'));
    }

    /**
     * E26.12: شرط «Require App Secret». كل نداء برمز يمر بـ Graph\GraphClient الذي يضيف appsecret_proof محسوباً على الرمز
     * المرسل نفسه، ومنه debug_token برمز التطبيق (E18)، فلا استثناء. نداء واحد يفوته يُرفض بعد التفعيل ويوقف الإرسال.
     */
    public function test_no_class_sends_an_access_token_outside_the_graph_client(): void
    {
        $offenders = array_keys(array_filter(
            $this->appFiles(),
            fn (string $code, string $path) => !str_starts_with($path, 'app/Services/WhatsApp/Graph/') && $this->sendsTokenItself($code),
            ARRAY_FILTER_USE_BOTH,
        ));

        $this->assertSame([], $offenders);
    }

    public function test_the_guard_catches_a_raw_graph_call_built_by_hand(): void
    {
        foreach ([
            '<?php $r = Http::withToken($t)->get(WhatsAppChannel::graphBase()."/x");',
            '<?php $r = Http::timeout(5)->withToken($t)->post($u);',
            '<?php $r = Http::withHeaders(["Authorization" => "Bearer ".$t])->get($u);',
            '<?php $r = Http::get(GraphClient::url(["me"]));',
            '<?php $r = Http::get($u, ["access_token" => $t]);',
        ] as $snippet) {
            $this->assertTrue($this->sendsTokenItself($snippet), $snippet);
        }
        $this->assertFalse($this->sendsTokenItself('<?php // Http::withToken($t) في تعليق لا يُحسب'."\n"));
        $this->assertFalse($this->sendsTokenItself('<?php $r = Http::withHeaders(["x-goog-api-key" => $k])->post($u);'));
    }

    public function test_no_unofficial_whatsapp_library_is_installed(): void
    {
        $manifests = file_get_contents(base_path('composer.json')).file_get_contents(base_path('package.json'));

        foreach (['whatsapp-web.js', 'baileys', '@whiskeysockets', 'venom-bot', 'wppconnect', 'open-wa'] as $library) {
            $this->assertStringNotContainsStringIgnoringCase($library, $manifests);
        }
    }
}
