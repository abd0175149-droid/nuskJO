<?php

namespace Tests\Feature\Retention;

use App\Models\BackupRun;
use App\Support\Retention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** أساس دورة حياة البيانات (E13.2–E13.3): النسخة الحديثة شرط التقليم، وبصمة الرقم ثابتة لا تكشفه، والهجرة متكررة الأمان */
class RetentionSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_recent_successful_backup_counts_as_fresh(): void
    {
        $this->assertFalse(Retention::backupFresh());

        BackupRun::create(['reason' => 'pre-migrate', 'status' => 'ok', 'finished_at' => now()->subHour()]);
        BackupRun::create(['reason' => 'scheduled', 'status' => 'failed', 'finished_at' => now()->subHour()]);
        BackupRun::create(['reason' => 'scheduled', 'status' => 'ok', 'finished_at' => now()->subHours(30)]);
        $this->assertFalse(Retention::backupFresh());

        BackupRun::create(['reason' => 'scheduled', 'status' => 'ok', 'finished_at' => now()->subHours(3)]);
        $this->assertTrue(Retention::backupFresh());
    }

    public function test_the_subject_hmac_is_stable_and_does_not_contain_the_number(): void
    {
        $a = Retention::subjectHmac('16505551234');

        $this->assertSame($a, Retention::subjectHmac('16505551234'));
        $this->assertNotSame($a, Retention::subjectHmac('16505553333'));
        $this->assertSame(64, strlen($a));
        $this->assertStringNotContainsString('16505551234', $a);
    }

    public function test_the_lifecycle_migration_is_additive_and_idempotent(): void
    {
        $migration = require database_path('migrations/2026_10_14_000014_data_lifecycle.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('wa_erasure_requests'));
        $this->assertTrue(Schema::hasTable('wa_bot_usage_monthly'));
        $this->assertTrue(Schema::hasColumn('wa_conversations', 'erased_at'));
        $this->assertSame(1, DB::table('permissions')->where('slug', 'whatsapp.erase')->count());
    }
}
