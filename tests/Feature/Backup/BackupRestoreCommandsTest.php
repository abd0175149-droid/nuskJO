<?php

namespace Tests\Feature\Backup;

use App\Console\Commands\BackupSafeMode;
use App\Console\Commands\BackupVerify;
use App\Models\Setting;
use App\Models\WaBotSetting;
use App\Services\Backup\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

/** backup:verify (قبل الاستعادة وبعدها) وbackup:safe-mode. */
class BackupRestoreCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $live;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/nj-restore-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/public');
        File::put($this->dir.'/public/a.txt', 'media');
        $this->live = $this->dir.'/live.sqlite';

        $pdo = new PDO('sqlite:'.$this->live);
        foreach (['users', 'invoices', 'journal_entries', 'wa_messages'] as $t) {
            $pdo->exec("CREATE TABLE {$t} (id INTEGER PRIMARY KEY, created_at TEXT)");
        }
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
        $pdo->exec("INSERT INTO migrations (migration, batch) VALUES ('0001_01_01_000000_create_users_table', 1)");
        foreach (['users' => 3, 'invoices' => 5, 'journal_entries' => 8, 'wa_messages' => 4] as $t => $n) {
            for ($i = 0; $i < $n; $i++) {
                $pdo->exec("INSERT INTO {$t} (created_at) VALUES ('2026-10-01 10:00:00')");
            }
        }

        config([
            'backup.core_tables' => ['users', 'invoices', 'journal_entries', 'wa_messages'],
            'backup.append_only' => ['wa_messages'],
            'backup.required_nonempty' => ['users', 'invoices', 'journal_entries'],
            'backup.media_dirs' => [$this->dir.'/public'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function snapshot(): string
    {
        return (new SnapshotService($this->live, $this->dir.'/spool'))->run('manual')['path'];
    }

    private function useLiveDatabase(): void
    {
        // backup:verify --live يقرأ مسار الاتصال؛ الاختبار نفسه يبقى على :memory:
        config(['database.connections.sqlite.database' => $this->live]);
    }

    public function test_intact_snapshot_passes_strict(): void
    {
        $this->artisan('backup:verify', ['path' => $this->snapshot(), '--strict' => true])->assertSuccessful();
    }

    public function test_tampered_file_fails(): void
    {
        $snap = $this->snapshot();
        file_put_contents("{$snap}/storage-public.tar.gz", 'tampered');

        $this->artisan('backup:verify', ['path' => $snap])->assertFailed();
    }

    public function test_counts_must_match_manifest(): void
    {
        $snap = $this->snapshot();
        $manifest = json_decode(file_get_contents("{$snap}/manifest.json"), true);
        $manifest['row_counts']['invoices'] = 99;
        file_put_contents("{$snap}/manifest.json", json_encode($manifest));

        $this->artisan('backup:verify', ['path' => $snap])->assertFailed();
    }

    public function test_corrupted_database_fails(): void
    {
        $snap = $this->snapshot();
        $db = "{$snap}/database.sqlite";
        $bytes = file_get_contents($db);
        file_put_contents($db, substr($bytes, 0, 2048).str_repeat("\xFF", 2048).substr($bytes, 4096));
        // البصمة لم تعد تطابق أيضاً؛ نحدّثها ليُختبر integrity_check وحده
        $manifest = json_decode(file_get_contents("{$snap}/manifest.json"), true);
        $manifest['files']['database.sqlite']['sha256'] = hash_file('sha256', $db);
        file_put_contents("{$snap}/manifest.json", json_encode($manifest));

        $this->artisan('backup:verify', ['path' => $snap])->assertFailed();
    }

    public function test_strict_rejects_snapshot_newer_than_the_code(): void
    {
        $pdo = new PDO('sqlite:'.$this->live);
        $pdo->exec("INSERT INTO migrations (migration, batch) VALUES ('2099_01_01_000000_from_the_future', 2)");
        $snap = $this->snapshot();

        $this->artisan('backup:verify', ['path' => $snap])->assertSuccessful();
        $this->artisan('backup:verify', ['path' => $snap, '--strict' => true])->assertFailed();
    }

    public function test_strict_requires_media_archive(): void
    {
        config(['backup.media_dirs' => []]);
        $snap = $this->snapshot();

        $this->artisan('backup:verify', ['path' => $snap])->assertSuccessful();
        $this->artisan('backup:verify', ['path' => $snap, '--strict' => true])->assertFailed();
    }

    public function test_compare_flags_data_newer_than_the_snapshot(): void
    {
        $snap = $this->snapshot();
        $this->useLiveDatabase();

        $this->artisan('backup:verify', ['--live' => true, '--compare' => "{$snap}/manifest.json"])->assertSuccessful();

        (new PDO('sqlite:'.$this->live))->exec("INSERT INTO invoices (created_at) VALUES ('".now()->addMinute()->utc()->format('Y-m-d H:i:s')."')");

        $this->artisan('backup:verify', ['--live' => true, '--compare' => "{$snap}/manifest.json"])
            ->assertExitCode(BackupVerify::DATA_WOULD_BE_LOST);
    }

    public function test_expect_after_restore_allows_growth_but_not_loss(): void
    {
        $snap = $this->snapshot();
        $this->useLiveDatabase();
        $pdo = new PDO('sqlite:'.$this->live);

        $pdo->exec("INSERT INTO wa_messages (created_at) VALUES ('2026-10-14 10:00:00')");
        $this->artisan('backup:verify', ['--live' => true, '--expect' => "{$snap}/manifest.json"])->assertSuccessful();

        $pdo->exec('DELETE FROM invoices WHERE id = 1');
        $this->artisan('backup:verify', ['--live' => true, '--expect' => "{$snap}/manifest.json"])->assertFailed();
    }

    public function test_manifest_turns_a_plain_copy_into_a_verifiable_snapshot(): void
    {
        $out = $this->dir.'/20261015T081500Z-pre-restore';
        File::ensureDirectoryExists($out);
        copy($this->live, "{$out}/database.sqlite");

        $this->artisan('backup:manifest', ['dir' => $out])->assertSuccessful();

        $manifest = json_decode(file_get_contents("{$out}/manifest.json"), true);
        $this->assertSame('pre-restore', $manifest['reason']);
        $this->assertSame(5, $manifest['row_counts']['invoices']);
        $this->assertArrayNotHasKey('manifest.json', $manifest['files']);
        $this->artisan('backup:verify', ['path' => $out])->assertSuccessful();
    }

    public function test_safe_mode_locks_sending_and_clears_volatile_state(): void
    {
        WaBotSetting::current()->update(['enabled' => true, 'wa_suspended' => false]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => 0, 'created_at' => 0]);
        DB::table('cache')->insert(['key' => 'k', 'value' => 'v', 'expiration' => time() + 60]);
        DB::table('sessions')->insert(['id' => 's1', 'payload' => 'x', 'last_activity' => time()]);
        DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'db', 'queue' => 'default', 'payload' => '{}', 'exception' => 'e']);

        $this->artisan('backup:safe-mode', ['--restored-from' => '20261014T023000Z-scheduled', '--pre-restore' => '20261015T081500Z'])
            ->assertSuccessful();

        $s = WaBotSetting::current()->fresh();
        $this->assertFalse($s->enabled);
        $this->assertTrue($s->wa_suspended);
        foreach (['jobs', 'cache', 'sessions'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'failed_jobs يبقى للتحقيق');

        $pending = json_decode(Setting::get(BackupSafeMode::REVIEW_KEY), true);
        $this->assertSame('20261014T023000Z-scheduled', $pending['snapshot']);
        $this->assertSame(array_keys(BackupSafeMode::CHECKLIST), array_keys($pending['checklist']));
    }

    public function test_safe_mode_rejects_bad_names(): void
    {
        $this->artisan('backup:safe-mode', ['--restored-from' => '../x', '--pre-restore' => '20261015T081500Z'])->assertFailed();
        $this->assertNull(Setting::get(BackupSafeMode::REVIEW_KEY));
    }
}
