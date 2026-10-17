<?php

namespace App\Services\Backup;

use App\Models\BackupRun;
use App\Support\Retention;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use Symfony\Component\Process\Process;

/**
 * لقطة متسقة لقاعدة SQLite مع ملفات storage/app/public، ثم فحصها.
 *
 * الترتيب: مساحة كافية → أعداد الجداول الحية → VACUUM INTO → integrity_check →
 * قواعد «القاعدة الخطأ» → أرشيف الوسائط → manifest.json → إعادة تسمية ذرية → تدوير محلي.
 * أي فشل يحذف المجلد الجزئي ولا يترك نسخة نصف مكتوبة.
 */
class SnapshotService
{
    private string $dbPath;

    private string $spool;

    public function __construct(?string $dbPath = null, ?string $spool = null)
    {
        $this->dbPath = $dbPath ?? (string) config('database.connections.sqlite.database');
        $this->spool = rtrim($spool ?? (string) config('backup.spool_path'), '/');
    }

    /**
     * @return array{name:string,path:string,manifest:array}
     *
     * @throws BackupFailed
     */
    public function run(string $reason, ?string $label = null, bool $allowShrink = false, ?int $userId = null): array
    {
        if (!in_array($reason, BackupRun::REASONS, true)) {
            throw new BackupFailed("سبب غير معروف: {$reason}");
        }

        $run = $this->startRun($reason, $userId);
        $oldUmask = umask(0077);
        $partial = null;

        try {
            $this->assertRealDatabase();
            File::ensureDirectoryExists($this->spool, 0700);
            $mediaSize = $this->mediaBytesWithinCap();
            $this->assertDiskSpace($mediaSize ?? 0);

            $live = new SqliteInspector($this->dbPath);
            $liveAppendOnly = $live->rowCounts(config('backup.append_only', []));

            $name = now()->utc()->format('Ymd\THis\Z').'-'.$reason.($label ? '-'.preg_replace('/[^A-Za-z0-9_.-]/', '', $label) : '');
            $partial = "{$this->spool}/{$name}.partial";
            File::ensureDirectoryExists($partial, 0700);

            $copy = "{$partial}/database.sqlite";
            $this->snapshotDatabase($copy);

            $snap = new SqliteInspector($copy);
            $integrity = $snap->integrity();
            if ($integrity !== 'ok') {
                throw new BackupFailed("فحص سلامة النسخة فشل: {$integrity}");
            }

            $coreTables = config('backup.core_tables', []);
            $counts = $snap->rowCounts(array_values(array_unique(array_merge($coreTables, config('backup.append_only', [])))));
            $this->assertNotWrongDatabase($counts, $liveAppendOnly, $allowShrink);

            $mediaBytes = $mediaSize === null ? 0 : $this->archiveMedia("{$partial}/storage-public.tar.gz");
            // سجل المحو الخارجي (E13) يسافر مع النسخة: عند فقد الخادم كله لا مصدر غيره لإعادة المحو
            $ledger = (string) config('retention.erasure_ledger');
            if ($ledger !== '' && is_file($ledger)) {
                copy($ledger, "{$partial}/erasure-ledger.jsonl");
            }
            $journalMode = $live->journalMode();
            unset($snap, $live);

            $manifest = $this->describe($partial, $reason, $label, $journalMode, $mediaSize === null);
            $migrations = $manifest['migrations'];

            $final = "{$this->spool}/{$name}";
            if (file_exists($final)) {
                $final .= '-'.substr(bin2hex(random_bytes(3)), 0, 6);
                $name = basename($final);
            }
            if (!rename($partial, $final)) {
                throw new BackupFailed('تعذّرت إعادة تسمية النسخة');
            }
            $partial = null;
            $this->lockDown($final);
            $this->rotate();

            Retention::clearShrinkAllowance();
            $this->finishRun($run, [
                'status' => 'ok',
                'snapshot_name' => $name,
                'db_bytes' => filesize("{$final}/database.sqlite"),
                'media_bytes' => $mediaBytes,
                'manifest_sha256' => hash_file('sha256', "{$final}/manifest.json"),
                'integrity' => $integrity,
                'row_counts' => $counts,
                'migrations_count' => $migrations['count'],
            ]);

            return ['name' => $name, 'path' => $final, 'manifest' => $manifest];
        } catch (\Throwable $e) {
            if ($partial && is_dir($partial)) {
                File::deleteDirectory($partial);
            }
            $this->finishRun($run, ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);

            throw $e instanceof BackupFailed ? $e : new BackupFailed($e->getMessage(), 0, $e);
        } finally {
            umask($oldUmask);
        }
    }

    /**
     * يكتب manifest.json لمجلد فيه database.sqlite (وربما storage-public.tar.gz) ويعيده.
     * يستعمله run() ولقطة ما قبل الاستعادة (backup:manifest) لتكون أي لقطة قابلة للفحص والاستعادة.
     */
    public function describe(string $dir, string $reason, ?string $label = null, ?string $journalMode = null, bool $mediaSkipped = false): array
    {
        $db = new SqliteInspector("{$dir}/database.sqlite");
        $tables = array_values(array_unique(array_merge(config('backup.core_tables', []), config('backup.append_only', []))));

        $manifest = [
            'version' => 1,
            'created_at' => now()->utc()->toIso8601String(),
            'reason' => $reason,
            'label' => $label,
            'app_commit' => config('backup.app_commit'),
            'sqlite_version' => $db->sqliteVersion(),
            'journal_mode' => $journalMode ?? $db->journalMode(),
            'integrity' => $db->integrity(),
            'fk_violations' => $db->foreignKeyViolations(),
            'row_counts' => $db->rowCounts($tables),
            'migrations' => $db->migrations(),
            'files' => $this->hashes($dir),
            'media_skipped' => $mediaSkipped,
        ];
        unset($db);

        File::put("{$dir}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $manifest;
    }

    /** آخر manifest لنسخة ناجحة (للمقارنة)، أو null. */
    public function latestManifest(): ?array
    {
        foreach ($this->snapshots() as $dir) {
            $file = "{$dir}/manifest.json";
            if (is_file($file)) {
                return json_decode((string) file_get_contents($file), true) ?: null;
            }
        }

        return null;
    }

    /** مجلدات النسخ المكتملة، الأحدث أولاً. */
    public function snapshots(): array
    {
        if (!is_dir($this->spool)) {
            return [];
        }

        $dirs = array_filter(glob("{$this->spool}/*", GLOB_ONLYDIR) ?: [], fn ($d) => !str_ends_with($d, '.partial'));
        rsort($dirs);

        return array_values($dirs);
    }

    private function assertRealDatabase(): void
    {
        if ($this->dbPath === '' || $this->dbPath === ':memory:' || !is_file($this->dbPath)) {
            throw new BackupFailed('لا توجد قاعدة SQLite على ملف لنسخها');
        }
    }

    private function assertDiskSpace(int $mediaBytes): void
    {
        $free = @disk_free_space($this->spool);
        if ($free === false) {
            return;
        }

        $need = 3 * filesize($this->dbPath) + $mediaBytes;
        $floor = (int) config('backup.disk_min_free_mb', 5120) * 1024 * 1024;
        if ($free - $need < $floor) {
            throw new BackupFailed('المساحة الحرة لا تكفي للنسخة مع إبقاء الحد الأدنى فارغاً على القرص');
        }
    }

    /** حجم ملفات الوسائط بالبايت، وnull إن تجاوز السقف (فتُتخطى ويُنبَّه). */
    private function mediaBytesWithinCap(): ?int
    {
        $cap = (int) config('backup.media_max_mb', 1024) * 1024 * 1024;
        $total = 0;
        foreach (array_filter(config('backup.media_dirs', []), 'is_dir') as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $total += $file->isFile() ? $file->getSize() : 0;
                if ($total > $cap) {
                    return null;
                }
            }
        }

        return $total;
    }

    private function snapshotDatabase(string $dest): void
    {
        try {
            $pdo = new PDO('sqlite:'.$this->dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 30000');
            $pdo->exec('VACUUM INTO '.$pdo->quote($dest));
            unset($pdo);
        } catch (\Throwable $e) {
            // البديل: أداة sqlite3 بأمر .backup
            @unlink($dest);
            $p = new Process(['sqlite3', '-init', '/dev/null', '-cmd', '.timeout 30000', $this->dbPath, ".backup '{$dest}'"]);
            $p->setTimeout(600)->run();
            if (!$p->isSuccessful() || !is_file($dest)) {
                throw new BackupFailed('تعذّر أخذ اللقطة: '.$e->getMessage());
            }
        }
    }

    /** يرفض نسخة تبدو من «القاعدة الخطأ» أو قاعدة فارغة أو انكمشت فجأة. */
    private function assertNotWrongDatabase(array $counts, array $liveAppendOnly, bool $allowShrink): void
    {
        foreach (config('backup.required_nonempty', []) as $table) {
            if (($counts[$table] ?? 0) < 1) {
                throw new BackupFailed("الجدول {$table} فارغ أو غائب في النسخة؛ يبدو أنها القاعدة الخطأ");
            }
        }

        foreach ($liveAppendOnly as $table => $liveCount) {
            if ($liveCount !== null && ($counts[$table] ?? 0) < $liveCount) {
                throw new BackupFailed("الجدول {$table} في النسخة أقل من القاعدة الحية");
            }
        }

        if ($allowShrink) {
            return;
        }

        $previous = $this->latestManifest()['row_counts'] ?? [];
        $tolerance = (float) config('backup.shrink_tolerance', 0.9);
        // ما حذفه prune وforget منذ آخر نسخة انكماش مقصود (E13): يُطرح من المرجع
        $allowed = Retention::shrinkAllowance();
        foreach (config('backup.core_tables', []) as $table) {
            $before = isset($previous[$table]) ? max(0, $previous[$table] - ($allowed[$table] ?? 0)) : null;
            if ($before && ($counts[$table] ?? 0) < $before * $tolerance) {
                throw new BackupFailed("الجدول {$table} انكمش من {$before} إلى ".($counts[$table] ?? 0).'؛ استعمل --allow-shrink إن كان مقصوداً');
            }
        }
    }

    private function archiveMedia(string $dest): int
    {
        $dirs = array_filter(config('backup.media_dirs', []), 'is_dir');
        if (!$dirs) {
            return 0;
        }

        $args = ['tar', '-czf', $dest];
        foreach ($dirs as $dir) {
            $args[] = '-C';
            $args[] = dirname($dir);
            $args[] = basename($dir);
        }

        $p = new Process($args);
        $p->setTimeout(1800)->run();
        if (!$p->isSuccessful()) {
            throw new BackupFailed('تعذّر أرشفة الوسائط: '.trim($p->getErrorOutput()));
        }

        return (int) filesize($dest);
    }

    private function hashes(string $dir): array
    {
        $out = [];
        foreach (glob("{$dir}/*") ?: [] as $file) {
            if (is_file($file) && basename($file) !== 'manifest.json') {
                $out[basename($file)] = ['sha256' => hash_file('sha256', $file), 'bytes' => filesize($file)];
            }
        }

        return $out;
    }

    private function lockDown(string $dir): void
    {
        @chmod($dir, 0700);
        foreach (glob("{$dir}/*") ?: [] as $file) {
            @chmod($file, 0600);
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($dir, 'www-data');
            foreach (glob("{$dir}/*") ?: [] as $file) {
                @chown($file, 'www-data');
            }
        }
    }

    /** يبقي أحدث local_keep نسخة، ودائماً أحدث نسخة قبل الهجرة وأحدث نسخة قبل التفريغ. */
    private function rotate(): void
    {
        $keep = max(1, (int) config('backup.local_keep', 3));
        $all = $this->snapshots();
        $protected = [];

        foreach (['pre-migrate', 'pre-reset'] as $reason) {
            foreach ($all as $dir) {
                if (str_contains(basename($dir), "-{$reason}")) {
                    $protected[] = $dir;
                    break;
                }
            }
        }

        foreach (array_slice($all, $keep) as $dir) {
            if (!in_array($dir, $protected, true)) {
                File::deleteDirectory($dir);
            }
        }
    }

    private function startRun(string $reason, ?int $userId): ?BackupRun
    {
        try {
            if (!Schema::hasTable('backup_runs')) {
                return null;
            }

            return BackupRun::create([
                'reason' => $reason,
                'status' => 'running',
                'triggered_by' => $userId,
                'started_at' => now(),
            ]);
        } catch (\Throwable) {
            return null; // نسخة ما قبل أول هجرة تسبق إنشاء الجدول
        }
    }

    private function finishRun(?BackupRun $run, array $data): void
    {
        try {
            $run?->update($data + ['finished_at' => now()]);
        } catch (\Throwable) {
            // السجل مساعد فقط؛ مصدر الحقيقة manifest.json
        }
    }
}
