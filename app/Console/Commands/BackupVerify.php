<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupFailed;
use App\Services\Backup\SqliteInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * فحص نسخة قبل استعادتها، أو فحص القاعدة الحية بعدها (scripts/backup/restore.sh والتمرين).
 *
 *   php artisan backup:verify /restore/20261014T023000Z-scheduled --strict
 *   php artisan backup:verify --live --compare=- < manifest.json   # ما سيضيع لو استُعيدت النسخة (الرمز 3)
 *   php artisan backup:verify --live --expect=- < manifest.json    # بعد الاستعادة: لا جدول أقل من النسخة
 *
 * المخرجات أعداد وأسماء جداول فقط، بلا بيانات عملاء.
 */
class BackupVerify extends Command
{
    public const DATA_WOULD_BE_LOST = 3;

    protected $signature = 'backup:verify
        {path? : مجلد نسخة فيه database.sqlite وmanifest.json}
        {--live : فحص القاعدة الحية بدل مجلد}
        {--strict : يشترط أرشيف الوسائط، وأن كل هجرات النسخة موجودة كملفات في هذا الإصدار}
        {--expect= : مع --live: manifest (مسار أو - من stdin)؛ يفشل إن قلّ جدول عن أعداده}
        {--compare= : مع --live: manifest نسخة مرشّحة؛ يعرض ما سيضيع ويخرج بالرمز 3 إن وُجد}
        {--json : ملخص JSON}';

    protected $description = 'فحص سلامة نسخة احتياطية أو القاعدة الحية ومقارنتها بـ manifest';

    private array $report = ['ok' => true, 'errors' => []];

    public function handle(): int
    {
        try {
            if ($this->option('live')) {
                $code = $this->verifyLive();
            } else {
                $code = $this->verifyCandidate((string) $this->argument('path'));
            }
        } catch (BackupFailed|\JsonException|\PDOException $e) {
            $this->problem($e->getMessage());
            $code = self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($this->report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($this->report['errors'] as $error) {
                $this->error('✗ '.$error);
            }
            if ($code === self::SUCCESS) {
                $this->info('✅ الفحص سليم');
            }
        }

        return $code;
    }

    private function verifyCandidate(string $dir): int
    {
        $dir = rtrim($dir, '/');
        if ($dir === '' || !is_dir($dir)) {
            throw new BackupFailed('المجلد غير موجود');
        }

        $manifest = $this->readManifest("{$dir}/manifest.json");
        $this->report['snapshot'] = basename($dir);
        $this->report['created_at'] = $manifest['created_at'] ?? null;

        if (!is_file("{$dir}/database.sqlite")) {
            throw new BackupFailed('database.sqlite غير موجود في النسخة');
        }

        foreach ($manifest['files'] ?? [] as $name => $meta) {
            $file = "{$dir}/".basename((string) $name);
            if (!is_file($file)) {
                $this->problem("ملف مذكور في manifest غير موجود: {$name}");
            } elseif (!hash_equals((string) ($meta['sha256'] ?? ''), hash_file('sha256', $file))) {
                $this->problem("بصمة {$name} لا تطابق manifest");
            }
        }
        if (!isset($manifest['files']['database.sqlite'])) {
            $this->problem('manifest لا يذكر database.sqlite');
        }

        $db = new SqliteInspector("{$dir}/database.sqlite");
        $integrity = $db->integrity();
        $this->report['integrity'] = $integrity;
        if ($integrity !== 'ok') {
            $this->problem("integrity_check: {$integrity}");
        }

        $expected = $manifest['row_counts'] ?? [];
        $actual = $db->rowCounts(array_keys($expected));
        $this->report['row_counts'] = $actual;
        foreach ($expected as $table => $count) {
            if ($actual[$table] !== $count) {
                $this->problem("الجدول {$table}: في النسخة ".($actual[$table] ?? 'غائب')." وفي manifest {$count}");
            }
        }
        foreach (config('backup.required_nonempty', []) as $table) {
            $n = array_key_exists($table, $actual) ? $actual[$table] : $db->rowCounts([$table])[$table];
            if (($n ?? 0) < 1) {
                $this->problem("الجدول {$table} فارغ أو غائب");
            }
        }

        if ($this->option('strict')) {
            if (!isset($manifest['files']['storage-public.tar.gz'])) {
                $this->problem('النسخة بلا أرشيف وسائط (--strict)');
            }

            $unknown = array_values(array_filter(
                $db->migrationNames(),
                fn (string $m) => !is_file(database_path("migrations/{$m}.php"))
            ));
            $this->report['unknown_migrations'] = $unknown;
            if ($unknown) {
                $this->problem('النسخة أحدث من هذا الإصدار (هجرات بلا ملفات: '.count($unknown).')؛ استعد الكود والصورة المطابقين أولاً');
            }
        }

        return $this->report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function verifyLive(): int
    {
        $path = (string) config('database.connections.sqlite.database');
        $live = new SqliteInspector($path);

        $integrity = $live->integrity();
        $this->report['integrity'] = $integrity;
        if ($integrity !== 'ok') {
            $this->problem("integrity_check: {$integrity}");
        }

        if ($this->option('compare') !== null) {
            return $this->compareWithCandidate($live, $this->readManifest((string) $this->option('compare')));
        }

        if ($this->option('expect') !== null) {
            $manifest = $this->readManifest((string) $this->option('expect'));
            $expected = $manifest['row_counts'] ?? [];
            $actual = $live->rowCounts(array_keys($expected));
            $this->report['row_counts'] = $actual;

            // بعد الإقلاع قد تصل رسائل أعادت ميتا إرسالها، فالزيادة مقبولة والنقص لا
            foreach ($expected as $table => $count) {
                if ($count !== null && ($actual[$table] ?? -1) < $count) {
                    $this->problem("الجدول {$table}: الحي ".($actual[$table] ?? 'غائب')." أقل من النسخة {$count}");
                }
            }
        }

        return $this->report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function compareWithCandidate(SqliteInspector $live, array $manifest): int
    {
        $since = Carbon::parse((string) ($manifest['created_at'] ?? ''))->utc()->format('Y-m-d H:i:s');
        $rows = [];
        $loss = false;

        foreach ($manifest['row_counts'] ?? [] as $table => $candidate) {
            $liveCount = $live->rowCounts([$table])[$table];
            $newer = $live->countNewerThan($table, $since);
            $rows[] = [$table, $liveCount ?? '—', $candidate ?? '—', $newer ?? '—'];

            if (($newer ?? 0) > 0 || (int) $liveCount > (int) $candidate) {
                $loss = true;
            }
        }

        $this->report['since'] = $since;
        $this->report['tables'] = array_map(fn ($r) => array_combine(['table', 'live', 'candidate', 'live_newer'], $r), $rows);
        $this->report['data_would_be_lost'] = $loss;

        if (!$this->option('json')) {
            $this->table(['الجدول', 'الحي', 'النسخة', "أُنشئ بعد {$since}"], $rows);
            if ($loss) {
                $this->warn('⚠️ القاعدة الحية فيها بيانات بعد وقت النسخة ستضيع بالاستعادة (تُحفظ في لقطة ما قبل الاستعادة).');
            }
        }

        return $loss ? self::DATA_WOULD_BE_LOST : ($this->report['ok'] ? self::SUCCESS : self::FAILURE);
    }

    private function readManifest(string $source): array
    {
        $raw = $source === '-' ? stream_get_contents(STDIN) : (is_file($source) ? file_get_contents($source) : false);
        if ($raw === false || $raw === '') {
            throw new BackupFailed('manifest.json غير موجود أو فارغ');
        }

        $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1) {
            throw new BackupFailed('manifest.json بصيغة غير معروفة');
        }

        return $manifest;
    }

    private function problem(string $message): void
    {
        $this->report['ok'] = false;
        $this->report['errors'][] = $message;
    }
}
