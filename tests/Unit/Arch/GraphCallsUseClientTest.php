<?php

namespace Tests\Unit\Arch;

use Tests\TestCase;

/**
 * حارس معماري (E18): Graph\GraphClient هو الطريق الوحيد إلى ميتا (فلا يفوت نداء بلا appsecret_proof حين تفعّل E26
 * «Require App Secret»)، وMetaErrorCatalog الجدول الوحيد للرموز، ولا شيء في الخدمات يرفع الإيقاف آلياً.
 */
class GraphCallsUseClientTest extends TestCase
{
    private const CLIENT_DIR = 'app/Services/WhatsApp/Graph/';

    /** @return array<string, string> المسار النسبي => الكود بلا تعليقات */
    private function appCode(string $dir = 'app'): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = '';
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            $out[substr($file->getPathname(), strlen(base_path()) + 1)] = $code;
        }

        return $out;
    }

    private function offenders(string $pattern, string $dir = 'app'): array
    {
        return array_keys(array_filter($this->appCode($dir), fn (string $code) => preg_match($pattern, $code) === 1));
    }

    public function test_graph_host_and_bearer_tokens_live_only_in_the_client(): void
    {
        $outside = fn (array $files) => array_values(array_filter($files, fn ($f) => !str_starts_with($f, self::CLIENT_DIR)));

        $this->assertSame([], $outside($this->offenders('/graph\.facebook\.com/')), 'نداء Graph خارج GraphClient');
        $this->assertSame([], $outside($this->offenders('/Http::withToken\s*\(/')), 'رمز وصول يُرسل خارج GraphClient');
    }

    /** E23: الناقل لا يفتح طريقاً ثانياً: Cloud يفوّض إلى GraphClient وحده، وLog بلا Http أصلاً */
    public function test_the_transport_layer_adds_no_second_route_to_meta(): void
    {
        $files = array_filter($this->appCode(), fn ($f) => str_starts_with($f, 'app/Services/WhatsApp/Transport/'), ARRAY_FILTER_USE_KEY);

        $this->assertSame(['app/Services/WhatsApp/Transport/CloudApiTransport.php'], array_keys(array_filter($files, fn ($code) => str_contains($code, 'GraphClient'))));
        $this->assertSame([], array_keys(array_filter($files, fn ($code) => preg_match('/Http::|Guzzle\\Client|curl_/', $code) === 1)));
    }

    public function test_there_is_one_error_code_table(): void
    {
        $this->assertSame(['app/Services/WhatsApp/MetaErrorCatalog.php'], $this->offenders('/\b131047\b/'));
        $this->assertSame(['app/Services/WhatsApp/MetaErrorCatalog.php'], $this->offenders('/\b131026\s*=>/'));
    }

    public function test_nothing_outside_the_kill_switch_lifts_a_suspension(): void
    {
        $offenders = [];
        foreach (['app/Services', 'app/Jobs', 'app/Console', 'app/Listeners'] as $dir) {
            if (is_dir(base_path($dir))) {
                $offenders = [...$offenders, ...$this->offenders("/'wa_suspended'\s*=>\s*(false|0\b|\\$)/", $dir)];
            }
        }

        $this->assertSame([], $offenders, 'رفع الإيقاف يدوي من الإعدادات وحدها');
    }
}
