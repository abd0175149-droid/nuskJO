<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\SnapshotService;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * backup:verify --rehearse-migrations كما يشغّله التمرين: هجرات معلّقة تُطبَّق على النسخة وحدها،
 * والدفاتر تُدقَّق، وفك الأسرار يُفحص، ولا يعمل أبداً إن لم يكن الاتصال على النسخة.
 */
class DrillRehearsalTest extends TestCase
{
    private string $dir;

    private array $originalDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/nj-drill-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
        $this->originalDb = config('database.connections.sqlite');

        config([
            'backup.core_tables' => ['users', 'journal_entries'],
            'backup.append_only' => [],
            'backup.required_nonempty' => ['users'],
            'backup.media_dirs' => [],
        ]);
    }

    protected function tearDown(): void
    {
        config(['database.connections.sqlite' => $this->originalDb]);
        DB::purge('sqlite');
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @return array{0:int,1:string} رمز الخروج والمخرج (الهجرة الداخلية لها مخرجها المنفصل) */
    private function verify(array $args): array
    {
        $out = new BufferedOutput;
        $code = Artisan::call('backup:verify', $args, $out);

        return [$code, $out->fetch()];
    }

    private function useDatabase(string $path): void
    {
        config(['database.connections.sqlite.database' => $path]);
        DB::purge('sqlite');
    }

    /** قاعدة حية مهاجرة كاملة ناقصة آخر هجرة، ثم لقطة منها. */
    private function snapshotWithPendingMigration(?string $token = null): string
    {
        $live = $this->dir.'/live.sqlite';
        touch($live);
        $this->useDatabase($live);
        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        DB::table('users')->insert(['name' => 'مدير', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        if ($token) {
            DB::table('wa_bot_settings')->insert(['wa_token' => $token, 'created_at' => now(), 'updated_at' => now()]);
        }

        $path = (new SnapshotService($live, $this->dir.'/spool'))->run('manual')['path'];

        return $path;
    }

    public function test_rehearsal_applies_pending_migrations_to_the_copy_only(): void
    {
        $snap = $this->snapshotWithPendingMigration(Crypt::encryptString('secret-token'));
        $liveBefore = DB::table('migrations')->count();

        $this->useDatabase("{$snap}/database.sqlite");
        [, $output] = $this->verify(['path' => $snap, '--rehearse-migrations' => true, '--json' => true]);
        $report = json_decode(trim($output), true);

        $this->assertTrue($report['ok'], json_encode($report['errors'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $report['drill']['migrations_applied']);
        $this->assertTrue($report['drill']['migrate_ok']);
        $this->assertTrue($report['drill']['secrets_decrypt']);
        $this->assertSame(substr(hash('sha256', config('app.key')), 0, 12), $report['drill']['app_key_fingerprint']);
        $this->assertSame(0, $report['drill']['trial_balance_issues']);
        $this->assertArrayHasKey('ledger_divergences', $report['drill']);
        $this->assertStringNotContainsString('secret-token', $output);

        $this->useDatabase($this->dir.'/live.sqlite');
        $this->assertSame($liveBefore, DB::table('migrations')->count(), 'القاعدة الأصلية لم تُهاجر');
    }

    public function test_secrets_from_another_app_key_fail_the_drill(): void
    {
        $foreign = (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString('secret-token');
        $snap = $this->snapshotWithPendingMigration($foreign);

        $this->useDatabase("{$snap}/database.sqlite");
        [$code, $output] = $this->verify(['path' => $snap, '--rehearse-migrations' => true, '--json' => true]);
        $report = json_decode(trim($output), true);

        $this->assertSame(1, $code);
        $this->assertFalse($report['drill']['secrets_decrypt']);
    }

    public function test_refuses_to_migrate_when_not_connected_to_the_copy(): void
    {
        $snap = $this->snapshotWithPendingMigration();
        $live = $this->dir.'/live.sqlite';
        $before = DB::table('migrations')->count();   // الاتصال ما زال على live.sqlite

        [$code, $output] = $this->verify(['path' => $snap, '--rehearse-migrations' => true, '--json' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('DB_DATABASE', $output);
        $this->useDatabase($live);
        $this->assertSame($before, DB::table('migrations')->count());
    }
}
