<?php

namespace Tests\Unit\Docs;

use Tests\TestCase;

/**
 * المستودع عام (E25): لا سر ولا هاتف حقيقي ولا عنوان داخلي في أي وثيقة. الأنماط هي أنماط SecretRedactor (E06)
 * للأسرار، ومعها الهاتف الأردني والسعودي وشبكة 192.168 وكلمة مرور بقيمة. أمثلة الصيغ بين <> مثل <PHONE_NUMBER_ID>
 * لا تطابق أي نمط. وأرقام ميتا النموذجية في الاختبارات أمريكية فلا تطابق.
 */
class DocsNoSecretsTest extends TestCase
{
    /** @var array<string, string> النمط => ما يمثّله */
    public const PATTERNS = [
        '/AIza[0-9A-Za-z_\-]{35}/' => 'مفتاح Google',
        '/\bEAA[A-Za-z0-9]{20,}/' => 'رمز ميتا',
        '/sk-ant-[A-Za-z0-9_\-]{16,}/' => 'مفتاح Anthropic',
        '/base64:[A-Za-z0-9+\/]{43}=/' => 'APP_KEY',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/' => 'مفتاح خاص',
        '/(?<![\d])(?:\+?962|00962|0)7[789]\d{7}(?![\d])/' => 'هاتف أردني',
        '/(?<![\d])9665\d{8}(?![\d])/' => 'هاتف سعودي',
        '/\b192\.168\.\d{1,3}\.\d{1,3}\b/' => 'عنوان شبكة داخلية',
        '/(?<![a-z])password\s*=\s*(?!null\b|<|\$|`)[^\s`"\'<>]{3,}/i' => 'كلمة مرور بقيمة',
    ];

    /** @return list<string> الملفات بمسار نسبي */
    public static function files(): array
    {
        $files = ['SECURITY.md', 'CHANGELOG.md', 'README.md', '.env.example', 'public/.well-known/security.txt', '.github/pull_request_template.md'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('docs'), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (in_array($file->getExtension(), ['md', 'txt'], true)) {
                $files[] = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
            }
        }
        sort($files);

        return array_values(array_filter($files, fn ($f) => is_file(base_path($f))));
    }

    /** @return list<string> «ما يمثّله» لكل تطابق */
    public static function findings(string $text): array
    {
        $out = [];
        foreach (self::PATTERNS as $pattern => $what) {
            if (preg_match_all($pattern, $text, $m)) {
                foreach ($m[0] as $hit) {
                    $out[] = $what.' («'.mb_substr($hit, 0, 6).'…»)';
                }
            }
        }

        return $out;
    }

    public function test_no_document_carries_a_secret_a_real_phone_or_an_internal_address(): void
    {
        $files = self::files();
        $this->assertContains('docs/RUNBOOK.md', $files);
        $this->assertContains('SECURITY.md', $files);

        $problems = [];
        foreach ($files as $file) {
            foreach (explode("\n", (string) file_get_contents(base_path($file))) as $i => $line) {
                foreach (self::findings($line) as $finding) {
                    $problems[] = "{$file}:".($i + 1).": {$finding}";
                }
            }
        }

        $this->assertSame([], $problems, "احذف القيمة واكتب اسم المتغير أو صيغة بين <>:\n".implode("\n", $problems));
    }

    public function test_the_patterns_catch_what_they_should_and_spare_placeholders(): void
    {
        $this->assertNotEmpty(self::findings('WA_TOKEN=EAA'.str_repeat('x', 25)));
        $this->assertNotEmpty(self::findings('اتصل على 0791234567'));
        $this->assertNotEmpty(self::findings('+962781234567'));
        $this->assertNotEmpty(self::findings('966512345678'));
        $this->assertNotEmpty(self::findings('ssh 192.168.1.20'));
        $this->assertNotEmpty(self::findings('DB_PASSWORD=hunter22'));

        $this->assertSame([], self::findings('POST /{PHONE_NUMBER_ID} و<PHONE_NUMBER_ID> و`WA_TOKEN`'));
        $this->assertSame([], self::findings('REDIS_PASSWORD=null و DB_PASSWORD= و --reset-password'));
        $this->assertSame([], self::findings('رقم ميتا النموذجي 16505551234'));
    }
}
