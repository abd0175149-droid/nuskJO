<?php

namespace Tests\Unit\Docs;

use Tests\TestCase;

/**
 * كل متغير بيئة يقرؤه كودنا له سطر في .env.example، ولو معلّقاً «# KEY=» (E25). الملفات تُشتق من config/: كل ملف
 * ليس من ملفات Laravel والحزم الافتراضية يُفحص كاملاً، وفي الملفات الافتراضية تُفحص مفاتيحنا ببادئاتها وحدها، فلا
 * تُطلب عشرات متغيرات REDIS وAWS وPUSHER. والملف نفسه بلا قيمة تشبه سراً (أنماط DocsNoSecretsTest).
 */
class EnvExampleCoverageTest extends TestCase
{
    /** ملفات config من Laravel والحزم كما شُحنت (مع ما أضفناه إليها، ويُلتقط ببادئاتنا) */
    public const DEFAULT_FILES = ['app', 'auth', 'broadcasting', 'cache', 'cors', 'database', 'filesystems', 'logging', 'mail', 'queue', 'reverb', 'services', 'session', 'webauthn'];

    /** مفاتيحنا داخل الملفات الافتراضية */
    public const OUR_KEYS = '/^(WA_|OPS_|BACKUP_|AUDIT_|AUTHZ_|CSP_|MFA_|STEPUP_|NUSKJO_|INTEGRATIONS_|MCP_|TF_|CHROMIUM_|ERASURE_|GOOGLE_API_KEY$|GEMINI_API_KEY$|ANTHROPIC_API_KEY$|APP_COMMIT$|APP_BUILD_TIME$|SAR_TO_JOD$)/';

    private function example(): string
    {
        return (string) file_get_contents(base_path('.env.example'));
    }

    /** @return array<string, list<string>> الملف النسبي => المفاتيح المطلوبة */
    public static function required(): array
    {
        $out = [];
        foreach (glob(config_path('*.php')) as $path) {
            preg_match_all("/env\\(\\s*'([A-Z0-9_]+)'/", (string) file_get_contents($path), $m);
            $keys = array_values(array_unique($m[1]));
            if (in_array(basename($path, '.php'), self::DEFAULT_FILES, true)) {
                $keys = array_values(array_filter($keys, fn ($k) => preg_match(self::OUR_KEYS, $k) === 1));
            }
            if ($keys !== []) {
                $out['config/'.basename($path)] = $keys;
            }
        }

        return $out;
    }

    public function test_every_key_our_config_reads_is_listed_in_env_example(): void
    {
        $example = $this->example();
        $missing = [];
        foreach (self::required() as $file => $keys) {
            foreach ($keys as $key) {
                if (preg_match('/(^|[\s#\/])'.preg_quote($key, '/').'=/m', $example) !== 1) {
                    $missing[] = "{$file}: {$key}";
                }
            }
        }

        $this->assertSame([], $missing, "متغيرات بلا سطر في .env.example (أضف «# KEY=» داخل الكتلة المعنية):\n".implode("\n", $missing));
    }

    public function test_the_scan_covers_our_files_and_skips_framework_defaults(): void
    {
        $required = self::required();

        $this->assertContains('WA_GRAPH_VERSION', $required['config/services.php'] ?? []);
        $this->assertContains('WA_TOKEN', $required['config/services.php'] ?? []);
        $this->assertContains('AUTHZ_MODE', $required['config/authz.php'] ?? []);
        $this->assertNotEmpty($required['config/whatsapp.php'] ?? []);
        $this->assertNotContains('POSTMARK_API_KEY', $required['config/services.php'] ?? []);
        $this->assertArrayNotHasKey('config/database.php', $required, 'REDIS_HOST وأمثاله لا تُفحص');
    }

    public function test_env_example_holds_no_secret_like_value(): void
    {
        $problems = [];
        foreach (explode("\n", $this->example()) as $i => $line) {
            foreach (DocsNoSecretsTest::findings($line) as $finding) {
                $problems[] = '.env.example:'.($i + 1).": {$finding}";
            }
            // سطر فعّال لسر معروف يجب أن يبقى فارغاً
            if (preg_match('/^(WA_TOKEN|WA_APP_SECRET|WA_WEBHOOK_VERIFY_TOKEN|GOOGLE_API_KEY|GEMINI_API_KEY|ANTHROPIC_API_KEY|ERASURE_HMAC_KEY|OPS_METRICS_TOKEN)=(.+)$/', $line, $m)) {
                $problems[] = '.env.example:'.($i + 1).": {$m[1]} بقيمة";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
