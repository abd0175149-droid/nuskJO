<?php

namespace Tests\Feature\Backup;

use App\Models\BackupRun;
use App\Services\Backup\BackupFailed;
use App\Services\Backup\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

class SnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $db;

    private string $spool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/nj-backup-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/public/offer-cards');
        File::put($this->dir.'/public/offer-cards/card.png', 'PNG');
        $this->db = $this->dir.'/live.sqlite';
        $this->spool = $this->dir.'/spool';

        $pdo = new PDO('sqlite:'.$this->db);
        foreach (['users', 'invoices', 'journal_entries', 'wa_messages', 'audit_logs'] as $t) {
            $pdo->exec("CREATE TABLE {$t} (id INTEGER PRIMARY KEY, v TEXT)");
        }
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
        $pdo->exec("INSERT INTO migrations (migration, batch) VALUES ('0001_init', 1), ('0002_more', 1)");
        $this->seedRows($pdo, ['users' => 5, 'invoices' => 20, 'journal_entries' => 40, 'wa_messages' => 30, 'audit_logs' => 10]);

        config([
            'backup.core_tables' => ['users', 'invoices', 'journal_entries', 'wa_messages'],
            'backup.append_only' => ['wa_messages', 'audit_logs'],
            'backup.required_nonempty' => ['users', 'invoices', 'journal_entries'],
            'backup.media_dirs' => [$this->dir.'/public'],
            'backup.local_keep' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function seedRows(PDO $pdo, array $counts): void
    {
        foreach ($counts as $table => $n) {
            for ($i = 0; $i < $n; $i++) {
                $pdo->exec("INSERT INTO {$table} (v) VALUES ('x')");
            }
        }
    }

    private function service(): SnapshotService
    {
        return new SnapshotService($this->db, $this->spool);
    }

    public function test_snapshot_is_consistent_verified_and_locked_down(): void
    {
        $result = $this->service()->run('manual', 'pre-E01');
        $m = $result['manifest'];

        $this->assertDirectoryExists($result['path']);
        $this->assertSame('ok', $m['integrity']);
        $this->assertSame(20, $m['row_counts']['invoices']);
        $this->assertSame(['count' => 2, 'last' => '0002_more'], $m['migrations']);
        $this->assertSame(hash_file('sha256', $result['path'].'/database.sqlite'), $m['files']['database.sqlite']['sha256']);
        $this->assertArrayHasKey('storage-public.tar.gz', $m['files']);
        $this->assertSame('0700', substr(sprintf('%o', fileperms($result['path'])), -4));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($result['path'].'/database.sqlite')), -4));
        $this->assertSame([], glob($this->spool.'/*.partial'));

        $run = BackupRun::latest('id')->first();
        $this->assertSame('ok', $run->status);
        $this->assertSame($result['name'], $run->snapshot_name);
    }

    public function test_empty_required_table_means_wrong_database_and_leaves_nothing(): void
    {
        (new PDO('sqlite:'.$this->db))->exec('DELETE FROM invoices');

        try {
            $this->service()->run('manual');
            $this->fail('expected BackupFailed');
        } catch (BackupFailed $e) {
            $this->assertStringContainsString('invoices', $e->getMessage());
        }

        $this->assertSame([], glob($this->spool.'/*'));
        $this->assertSame('failed', BackupRun::latest('id')->value('status'));
    }

    public function test_sudden_shrink_is_rejected_unless_allowed(): void
    {
        $this->service()->run('manual', 'a');
        (new PDO('sqlite:'.$this->db))->exec('DELETE FROM journal_entries WHERE id > 10');

        $this->expectException(BackupFailed::class);
        try {
            $this->service()->run('manual', 'b');
        } finally {
            $this->assertCount(1, $this->service()->snapshots());
            $this->service()->run('manual', 'c', allowShrink: true);
            $this->assertCount(2, $this->service()->snapshots());
        }
    }

    public function test_shrink_recorded_by_retention_is_allowed_once_then_cleared(): void
    {
        config(['retention.shrink_ledger' => $this->dir.'/shrink.json']);
        $this->service()->run('manual', 'a');
        (new PDO('sqlite:'.$this->db))->exec('DELETE FROM journal_entries WHERE id > 10');
        \App\Support\Retention::recordShrink(['journal_entries' => 30]);

        $this->service()->run('manual', 'b');

        $this->assertCount(2, $this->service()->snapshots(), 'الحذف المسجّل لا يُعدّ «قاعدة خطأ»');
        $this->assertSame([], \App\Support\Retention::shrinkAllowance(), 'يُمسح بعد نسخة ناجحة');
    }

    public function test_the_erasure_ledger_travels_with_the_snapshot(): void
    {
        config(['retention.erasure_ledger' => $this->dir.'/erasure-ledger.jsonl']);
        $this->assertArrayNotHasKey('erasure-ledger.jsonl', $this->service()->run('manual', 'a')['manifest']['files']);

        \App\Support\Retention::appendErasureLedger(['subject_hmac' => 'abc', 'done_at' => '2026-10-14T10:00:00Z']);
        $result = $this->service()->run('manual', 'b');

        $this->assertSame(hash_file('sha256', $this->dir.'/erasure-ledger.jsonl'), $result['manifest']['files']['erasure-ledger.jsonl']['sha256']);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($result['path'].'/erasure-ledger.jsonl')), -4));
    }

    public function test_rotation_keeps_latest_and_latest_pre_migrate(): void
    {
        config(['backup.local_keep' => 2]);

        $this->travelTo(now()->subMinutes(4));
        $this->service()->run('pre-migrate', 'a');
        foreach (['b', 'c', 'd'] as $label) {
            $this->travel(1)->minutes();
            $this->service()->run('manual', $label);
        }
        $this->travelBack();

        $names = array_map('basename', $this->service()->snapshots());
        $this->assertCount(3, $names);
        $this->assertTrue(collect($names)->contains(fn ($n) => str_contains($n, 'pre-migrate')));
        $this->assertTrue(collect($names)->contains(fn ($n) => str_ends_with($n, '-d')));
    }

    public function test_media_over_the_cap_is_skipped_but_database_is_backed_up(): void
    {
        config(['backup.media_max_mb' => 0]);

        $result = $this->service()->run('manual');

        $this->assertTrue($result['manifest']['media_skipped']);
        $this->assertFileDoesNotExist($result['path'].'/storage-public.tar.gz');
        $this->assertFileExists($result['path'].'/database.sqlite');
        $this->assertSame(20, $result['manifest']['row_counts']['invoices']);
    }

    public function test_refuses_when_backup_would_eat_the_disk_floor(): void
    {
        config(['backup.disk_min_free_mb' => PHP_INT_MAX >> 21]);

        try {
            $this->service()->run('manual');
            $this->fail('كان يجب أن يرفض');
        } catch (BackupFailed $e) {
            $this->assertStringContainsString('المساحة', $e->getMessage());
        }
        $this->assertSame([], glob($this->spool.'/*') ?: []);
    }

    public function test_unknown_reason_is_refused(): void
    {
        $this->artisan('backup:run', ['--reason' => 'whenever'])->assertFailed();
    }

    public function test_reset_data_refuses_without_a_backup(): void
    {
        // القاعدة الافتراضية في الاختبار :memory: فلا يمكن نسخها، فيجب أن يُرفض التفريغ
        $this->artisan('app:reset-data', ['--confirm' => true])->assertFailed();
    }
}
