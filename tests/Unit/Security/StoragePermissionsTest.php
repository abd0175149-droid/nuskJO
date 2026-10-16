<?php

namespace Tests\Unit\Security;

use Tests\TestCase;

/**
 * صلاحيات storage المتناسبة (E26.7) دون أن تطغى على مجلدي الجوازات والنسخ (A17): كل find شامل على storage يستثنيهما
 * بـ -prune، في التطبيق وفي التراجع، والتشديد خلف NUSKJO_STORAGE_PERMS=strict حتى يُتحقق من ضم nginx إلى المجموعة.
 */
class StoragePermissionsTest extends TestCase
{
    private function entrypoint(): string
    {
        return (string) file_get_contents(base_path('docker/entrypoint.sh'));
    }

    public function test_every_storage_wide_find_skips_the_passport_and_backup_folders(): void
    {
        preg_match_all('/^\s*find storage\s.*$/m', $this->entrypoint(), $wide);

        $this->assertCount(2, $wide[0], 'مجلدات وملفات');
        foreach ($wide[0] as $line) {
            $this->assertStringContainsString('-path storage/app/wa-media', $line);
            $this->assertStringContainsString('-path storage/app/backups', $line);
            $this->assertMatchesRegularExpression('/\\\\\) -prune -o -type [df] -exec chmod (750|640) \{\} \+/', $line);
        }
        $this->assertDoesNotMatchRegularExpression('/chmod -R \d+ storage(\s|$)/m', $this->entrypoint());
    }

    public function test_strict_mode_is_opt_in_and_legacy_restores_readable_public_files(): void
    {
        $sh = $this->entrypoint();

        $this->assertStringContainsString('if [ "${NUSKJO_STORAGE_PERMS:-legacy}" = "strict" ]; then', $sh);
        $this->assertStringContainsString('chmod 750 bootstrap/cache && find bootstrap/cache -type f -exec chmod 640 {} +', $sh);
        $this->assertStringContainsString('chmod 755 storage storage/app', $sh, 'التراجع يعيد العبور إلى القرص العام');
        $this->assertStringContainsString('find storage/app/public -type d -exec chmod 755 {} +', $sh);
        $this->assertStringContainsString('find storage/app/public -type f -exec chmod 644 {} +', $sh);
        // بعد كل ما ينشئه root أثناء الإقلاع (الكاش والمسارات وdoctor)
        $this->assertGreaterThan(strpos($sh, 'php artisan config:cache'), strpos($sh, 'NUSKJO_STORAGE_PERMS'));
        // مرفقات العملاء تبقى 700/600 (E14)
        $this->assertStringContainsString('find storage/app/wa-media -type d -exec chmod 700', $sh);
        $this->assertStringContainsString('find storage/app/wa-media -type f -exec chmod 600', $sh);
    }

    public function test_new_files_follow_the_same_mode_and_the_archive_disk_keeps_its_own(): void
    {
        $load = function (?string $mode): array {
            $backup = [$_ENV['NUSKJO_STORAGE_PERMS'] ?? null, $_SERVER['NUSKJO_STORAGE_PERMS'] ?? null];
            if ($mode === null) {
                unset($_ENV['NUSKJO_STORAGE_PERMS'], $_SERVER['NUSKJO_STORAGE_PERMS']);
            } else {
                $_ENV['NUSKJO_STORAGE_PERMS'] = $_SERVER['NUSKJO_STORAGE_PERMS'] = $mode;
            }
            try {
                return require config_path('filesystems.php');
            } finally {
                foreach (['_ENV', '_SERVER'] as $i => $global) {
                    if ($backup[$i] === null) {
                        unset($GLOBALS[$global]['NUSKJO_STORAGE_PERMS']);
                    } else {
                        $GLOBALS[$global]['NUSKJO_STORAGE_PERMS'] = $backup[$i];
                    }
                }
            }
        };

        $strict = $load('strict');
        foreach (['local', 'public'] as $disk) {
            $this->assertSame(['file' => ['public' => 0640, 'private' => 0600], 'dir' => ['public' => 0750, 'private' => 0700]], $strict['disks'][$disk]['permissions']);
        }
        $this->assertSame(0600, $strict['disks']['wa_media']['permissions']['file']['public']);

        $legacy = $load(null);
        $this->assertSame([], $legacy['disks']['public']['permissions'], 'افتراضي Flysystem قبل ضم nginx');
        $this->assertSame(0700, $legacy['disks']['wa_media']['permissions']['dir']['private']);
    }

    public function test_log_files_are_not_readable_by_others(): void
    {
        foreach (['single', 'daily', 'wa_json'] as $channel) {
            $this->assertSame(0640, config("logging.channels.{$channel}.permission"), $channel);
        }
    }
}
