<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\GraphVersions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** جدول نهاية دعم إصدارات Graph: التحقق من الصيغة، والأيام المتبقية، ومستوى التنبيه. */
class GraphVersionsTest extends TestCase
{
    public function test_version_format(): void
    {
        foreach (['v25.0', 'v26.0', 'v99.0'] as $ok) {
            $this->assertTrue(GraphVersions::isValid($ok), $ok);
        }
        foreach ([null, '', 'v25', 'v25.1', '25.0', 'v25.0/..', 'v5.0'] as $bad) {
            $this->assertFalse(GraphVersions::isValid($bad), (string) $bad);
        }
    }

    public function test_levels_follow_the_sunset_date(): void
    {
        $this->assertSame('ok', GraphVersions::status('v25.0', Carbon::parse('2028-05-01'))['level']);
        $this->assertSame(['version' => 'v25.0', 'sunset' => '2028-07-29', 'days_left' => 60, 'level' => 'warn'],
            GraphVersions::status('v25.0', Carbon::parse('2028-05-30')));
        $this->assertSame('expired', GraphVersions::status('v25.0', Carbon::parse('2028-07-29'))['level']);
        $this->assertSame('ok', GraphVersions::status('v26.0', Carbon::parse('2030-01-01'))['level'], 'لم تُعلن نهايته');
        $this->assertSame('unknown', GraphVersions::status('v99.0')['level']);
        $this->assertSame('unknown', GraphVersions::status(null)['level']);
    }

    public function test_the_configured_default_is_a_known_supported_version(): void
    {
        $status = GraphVersions::status(config('services.whatsapp.graph_version'), Carbon::parse('2026-10-14'));

        $this->assertSame('ok', $status['level']);
    }

    public function test_old_version_literals_live_only_in_the_table(): void
    {
        $offenders = [];
        foreach (['app', 'config'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php' && $file->getFilename() !== 'GraphVersions.php'
                    && preg_match('/[\'"]v2[0-4]\.0[\'"]/', file_get_contents($file->getPathname()))) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
