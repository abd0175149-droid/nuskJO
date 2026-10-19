<?php

namespace Tests\Unit\Database;

use App\Database\ImmediateTransactionPdo;
use App\Database\SQLiteImmediateConnector;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * المعاملة تحجز الكتابة من بدايتها (BEGIN IMMEDIATE) على PHP 8.3 و8.4 معاً: كاتب آخر بمهلة صفر يُرفض ما دامت
 * معاملتنا مفتوحة ولو لم تكتب بعد. بلا ذلك تبدأ DEFERRED، وحجز وظيفة من الطابور يفشل بـ «database is locked».
 */
class ImmediateTransactionTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'immediate').'.sqlite';
        touch($this->file);
        config(['database.connections.immediate_probe' => [
            'driver' => 'sqlite', 'database' => $this->file, 'prefix' => '', 'foreign_key_constraints' => true,
            'journal_mode' => 'wal', 'busy_timeout' => 1000, 'synchronous' => 'normal', 'transaction_mode' => 'IMMEDIATE',
        ]]);
        DB::connection('immediate_probe')->statement('create table probe (id integer primary key, v text)');
    }

    protected function tearDown(): void
    {
        DB::purge('immediate_probe');
        foreach ([$this->file, $this->file.'-wal', $this->file.'-shm'] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function otherWriter(): PDO
    {
        $pdo = new PDO('sqlite:'.$this->file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 0');

        return $pdo;
    }

    public function test_a_transaction_takes_the_write_lock_before_its_first_write(): void
    {
        $other = $this->otherWriter();
        $blocked = null;

        DB::connection('immediate_probe')->transaction(function ($db) use ($other, &$blocked) {
            $db->table('probe')->count();   // قراءة فقط حتى الآن
            try {
                $other->exec("insert into probe (v) values ('other')");
                $blocked = false;
            } catch (PDOException) {
                $blocked = true;
            }
            $db->table('probe')->insert(['v' => 'ours']);
        });

        $this->assertTrue($blocked, 'المعاملة لم تحجز الكتابة عند بدايتها (DEFERRED)');
        $this->assertSame(['ours'], DB::connection('immediate_probe')->table('probe')->pluck('v')->all());
    }

    public function test_commit_rollback_and_nested_savepoints_behave_as_before(): void
    {
        $db = DB::connection('immediate_probe');

        $db->transaction(function ($db) {
            $db->table('probe')->insert(['v' => 'outer']);
            try {
                $db->transaction(function ($db) {
                    $db->table('probe')->insert(['v' => 'inner']);
                    throw new \RuntimeException('inner fails');
                });
            } catch (\RuntimeException) {
                // الداخلية وحدها تُتراجع (savepoint)
            }
        });
        try {
            $db->transaction(function ($db) {
                $db->table('probe')->insert(['v' => 'lost']);
                throw new \RuntimeException('outer fails');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(['outer'], $db->table('probe')->pluck('v')->all());
        $this->assertSame(0, $db->transactionLevel());
        $this->assertFalse($db->getPdo()->inTransaction());
        // الاتصال صالح لمعاملة تالية
        $db->transaction(fn ($db) => $db->table('probe')->insert(['v' => 'after']));
        $this->assertSame(2, $db->table('probe')->count());
    }

    public function test_the_custom_pdo_is_used_only_where_laravel_cannot_do_it(): void
    {
        $pdo = DB::connection('immediate_probe')->getPdo();
        if (PHP_VERSION_ID < 80400) {
            $this->assertInstanceOf(ImmediateTransactionPdo::class, $pdo);
            $this->assertSame('IMMEDIATE', $pdo->transactionMode());
        } else {
            $this->assertNotInstanceOf(ImmediateTransactionPdo::class, $pdo, 'على 8.4 يتولاه Laravel نفسه');
        }
        $this->assertNull(SQLiteImmediateConnector::needed('DEFERRED'));
        $this->assertNull(SQLiteImmediateConnector::needed(null));
    }

    public function test_rollback_after_sqlite_already_rolled_back_keeps_the_original_error_visible(): void
    {
        $pdo = new ImmediateTransactionPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->beginTransaction();
        $pdo->exec('ROLLBACK');   // كأن SQLite تراجعت وحدها

        $this->assertTrue($pdo->rollBack());
        $this->assertFalse($pdo->inTransaction());
        $this->expectException(PDOException::class);
        $pdo->commit();
    }
}
