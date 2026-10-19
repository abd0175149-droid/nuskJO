<?php

namespace Tests\Unit\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ملف القاعدة يفتح بـ WAL ومهلة قفل 60 ثانية وsynchronous=normal (E07.11). القيم الأقل كانت تُظهر
 * «database is locked» حين يكتب الويبهوك ورد البوت والصفحات معاً.
 */
class SqliteWalConfigTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'waltest');
        config(['database.connections.waltest' => ['database' => $this->file] + config('database.connections.sqlite')]);
    }

    protected function tearDown(): void
    {
        DB::purge('waltest');
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->file.$suffix);
        }
        parent::tearDown();
    }

    private function pragma(string $name): string
    {
        return (string) array_values((array) DB::connection('waltest')->selectOne("PRAGMA {$name}"))[0];
    }

    public function test_a_file_database_opens_in_wal_with_a_long_busy_timeout(): void
    {
        $this->assertSame('wal', $this->pragma('journal_mode'));
        $this->assertSame('60000', $this->pragma('busy_timeout'));
        $this->assertSame('1', $this->pragma('synchronous'));   // NORMAL
    }

    public function test_compose_sets_the_same_values_explicitly(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        foreach (['DB_JOURNAL_MODE=wal', 'DB_BUSY_TIMEOUT=60000', 'DB_SYNCHRONOUS=normal', 'DB_TRANSACTION_MODE=IMMEDIATE'] as $line) {
            $this->assertStringContainsString('- '.$line, $compose);
        }
    }
}
