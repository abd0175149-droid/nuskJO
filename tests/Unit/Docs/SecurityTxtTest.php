<?php

namespace Tests\Unit\Docs;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * قناة الإبلاغ عن الثغرات (E25، RFC 9116 والبند 6.a من شروط ميتا): public/.well-known/security.txt بحقلَي Contact
 * وExpires الإلزاميين وPolicy، والبريد نفسه في SECURITY.md وفي /privacy#security، وكتلة nginx تخدم الملف وحده
 * نصاً قبل حجب الملفات النقطية.
 */
class SecurityTxtTest extends TestCase
{
    /** @return array<string, list<string>> */
    public static function fields(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            if (preg_match('/^([A-Za-z-]+):\s*(.+?)\s*$/', $line, $m)) {
                $out[strtolower($m[1])][] = $m[2];
            }
        }

        return $out;
    }

    private function securityTxt(): string
    {
        return (string) file_get_contents(public_path('.well-known/security.txt'));
    }

    public function test_contact_and_policy_are_valid_and_match_the_other_channels(): void
    {
        $fields = self::fields($this->securityTxt());

        $this->assertNotEmpty($fields['contact'] ?? [], 'Contact إلزامي');
        foreach ($fields['contact'] as $contact) {
            $this->assertMatchesRegularExpression('#^(mailto:[^@\s]+@[^@\s]+|https://\S+)$#', $contact);
        }
        $this->assertCount(1, $fields['expires'] ?? [], 'Expires إلزامي ومرة واحدة');
        $this->assertMatchesRegularExpression('#(SECURITY\.md|/privacy\#security|/security/policy)$#', $fields['policy'][0] ?? '', 'Policy يشير إلى SECURITY.md أو /privacy#security');
        $this->assertSame(['https://nuskjo.grade.sbs/.well-known/security.txt'], $fields['canonical'] ?? []);

        $mail = collect($fields['contact'])->first(fn ($c) => str_starts_with($c, 'mailto:'));
        $this->assertNotNull($mail);
        $address = substr($mail, 7);
        $this->assertStringContainsString($address, (string) file_get_contents(base_path('SECURITY.md')), 'البريد نفسه في SECURITY.md');
        $privacy = (string) file_get_contents(resource_path('views/legal/privacy.blade.php'));
        $this->assertMatchesRegularExpression('/<h2 id="security">[^<]+<\/h2>\s*<p>[^\n]*'.preg_quote($address, '/').'[^\n]*72 ساعة/u', $privacy, '/privacy#security بالبريد نفسه ومهلة 72 ساعة');
        $this->assertStringContainsString('id="security-en"', $privacy);
    }

    #[Group('horizon')]
    public function test_expires_is_renewed_yearly(): void
    {
        $expires = self::fields($this->securityTxt())['expires'][0] ?? '';
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $expires, 'Expires بصيغة ISO 8601');

        $at = Carbon::parse($expires);
        $this->assertTrue($at->greaterThan(now()->addDays(30)), "Expires {$expires} أقرب من 30 يوماً: جدّده (سنة على الأكثر) في public/.well-known/security.txt");
        $this->assertTrue($at->lessThan(now()->addDays(366)), "Expires {$expires} أبعد من سنة: RFC 9116 يوصي بأقل من سنة");
    }

    public function test_nginx_serves_only_this_file_as_plain_text_before_the_dotfile_block(): void
    {
        $conf = (string) file_get_contents(base_path('docker/nginx.conf'));
        $code = (string) preg_replace('/#[^\n]*/', '', $conf);

        $this->assertMatchesRegularExpression('/location = \/\.well-known\/security\.txt \{\s*default_type text\/plain;\s*try_files \$uri =404;\s*\}/', $code);
        $this->assertStringNotContainsString('location ^~ /.well-known', $code, 'لا تُفتح /.well-known كلها');
        $this->assertMatchesRegularExpression('/location ~ \/\\\\\. \{\s*deny all;/', $code, 'حجب الملفات النقطية باقٍ');
    }

    public function test_date_bound_tests_stay_in_ci_and_out_of_the_deploy_gate(): void
    {
        $this->assertStringContainsString('"--exclude-group=horizon"', (string) file_get_contents(base_path('Dockerfile.test')));
        $this->assertStringNotContainsString('exclude-group', (string) file_get_contents(base_path('.github/workflows/ci.yml')));
        // الوثائق تدخل حاوية البوابة (اختبارات التوثيق) ولا تدخل صورة الإنتاج
        $gate = (string) file_get_contents(base_path('Dockerfile.test.dockerignore'));
        $this->assertDoesNotMatchRegularExpression('/^(docs\/?|\*\*?\/?\*?\.md|SECURITY\.md|public\/\.well-known.*)$/m', $gate);
        $image = (string) file_get_contents(base_path('.dockerignore'));
        $this->assertMatchesRegularExpression('/^docs\/$/m', $image);
        $this->assertMatchesRegularExpression('/^\*\*\/\*\.md$/m', $image);
    }

    public function test_an_expired_or_missing_contact_would_fail(): void
    {
        $this->assertSame([], self::fields("# comment only\n")['contact'] ?? []);
        $soon = Carbon::now()->addDays(10);
        $this->assertFalse($soon->greaterThan(now()->addDays(30)));
    }
}
