<?php

namespace Tests\Unit\Docs;

use App\Models\WaBotSetting;
use App\Support\FeatureFlags;
use Tests\TestCase;

/**
 * كل علم مقبض تراجع موثَّق في docs/RUNBOOK.md «أعلام الميزات (مقابض التراجع)» (E25): أعلام FeatureFlags::DEFINITIONS،
 * وأعلام الموثوقية في الإعدادات، وأعلام البيئة التشغيلية أدناه. صف واحد لكل علم يبدأ بـ «| `العلم` |»، فإضافة علم
 * سطر واحد في الجدول.
 */
class FeatureFlagsDocumentedTest extends TestCase
{
    /** متغيرات بيئة تعمل مقبض تراجع لا ضبطاً. متغير تشغيلي جديد بهذا الدور يُضاف هنا وفي الجدول */
    public const ENV_FLAGS = [
        'WA_SUSPENDED', 'OPS_HEALTH_CHECKS', 'WA_META_CHECKS', 'AUTHZ_MODE', 'WA_FENCE_QUOTES', 'WA_APPSECRET_PROOF',
        'WA_MEDIA_HOST_MODE', 'WA_AUTOSUSPEND_CODES', 'OUTBOUND_HTTP_NO_REDIRECTS', 'CHROMIUM_NET_ISOLATION', 'CSP_MODE',
        'MFA_ENFORCE', 'MFA_CHALLENGE', 'BACKUP_ENABLED', 'BACKUP_PRE_MIGRATE', 'AUDIT_EXTENDED', 'WA_TRANSPORT',
        'BOOT_GUARD', 'NUSKJO_STORAGE_PERMS',
    ];

    private function section(): string
    {
        $doc = (string) file_get_contents(base_path('docs/RUNBOOK.md'));

        return preg_match('/^## أعلام الميزات \(مقابض التراجع\)\n(.*?)(?=^## |\z)/msu', $doc, $m) ? $m[1] : '';
    }

    /** @param list<string> $flags */
    private function assertDocumented(array $flags, string $kind): void
    {
        $section = $this->section();
        $this->assertNotSame('', $section, 'قسم «## أعلام الميزات (مقابض التراجع)» غائب عن docs/RUNBOOK.md');

        $missing = array_values(array_filter($flags, fn ($flag) => preg_match('/^\| `'.preg_quote($flag, '/').'` \|/m', $section) !== 1));
        $this->assertSame([], $missing, "{$kind} بلا صف «| `العلم` | الملحمة | … |» في docs/RUNBOOK.md «أعلام الميزات (مقابض التراجع)»: ".implode('، ', $missing));
    }

    public function test_every_feature_flag_has_a_row(): void
    {
        $this->assertDocumented(array_keys(FeatureFlags::DEFINITIONS), 'أعلام FeatureFlags::DEFINITIONS');
    }

    public function test_every_reliability_flag_has_a_row(): void
    {
        $this->assertDocumented(array_keys(WaBotSetting::RELIABILITY_FLAGS), 'أعلام الموثوقية WaBotSetting::RELIABILITY_FLAGS');
    }

    public function test_every_operational_environment_flag_has_a_row_and_is_read_by_a_config_file(): void
    {
        $this->assertDocumented(self::ENV_FLAGS, 'أعلام البيئة التشغيلية');

        $config = implode("\n", array_map('file_get_contents', glob(config_path('*.php'))));
        $docker = implode("\n", array_map('file_get_contents', glob(base_path('docker/*'))));
        foreach (self::ENV_FLAGS as $flag) {
            $this->assertTrue(str_contains($config, "'{$flag}'") || str_contains($docker, $flag), "{$flag} لم يعد يُقرأ: احذفه من الجدول ومن ENV_FLAGS");
        }
    }
}
