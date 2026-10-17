<?php

namespace Tests\Feature\Backup;

use App\Models\BackupRun;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** الأوامر التي تستدعيها سكربتات المضيف (scripts/backup) وتدوير الأرشيفات. */
class BackupHostCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const SNAP = '20261014T023000Z-scheduled';

    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_mark_collected_updates_the_run(): void
    {
        $run = BackupRun::create(['reason' => 'scheduled', 'status' => 'ok', 'snapshot_name' => self::SNAP]);

        $this->artisan('backup:mark', [
            'snapshot' => self::SNAP,
            '--collected' => true,
            '--archive' => 'nuskjo-'.self::SNAP.'.tar.gz.age',
            '--bytes' => '1234',
            '--sha256' => self::SHA,
        ])->assertSuccessful();

        $run->refresh();
        $this->assertNotNull($run->collected_at);
        $this->assertSame(1234, (int) $run->archive_bytes);
        $this->assertSame(self::SHA, $run->archive_sha256);
    }

    public function test_mark_pulled_and_drill(): void
    {
        $run = BackupRun::create(['reason' => 'scheduled', 'status' => 'ok', 'snapshot_name' => self::SNAP]);

        $this->artisan('backup:mark', ['snapshot' => self::SNAP, '--pulled' => true])->assertSuccessful();
        $this->artisan('backup:mark', ['snapshot' => self::SNAP, '--drill' => 'ok', '--summary' => '{"integrity":"ok","seconds":42}'])
            ->assertSuccessful();

        $run->refresh();
        $this->assertNotNull($run->pulled_at);
        $this->assertSame('ok', $run->drill_summary['result']);
        $this->assertSame(42, $run->drill_summary['seconds']);
    }

    public function test_mark_rejects_bad_input_and_tolerates_unknown_snapshot(): void
    {
        $this->artisan('backup:mark', ['snapshot' => '../../etc', '--pulled' => true])->assertFailed();
        $this->artisan('backup:mark', ['snapshot' => self::SNAP, '--collected' => true, '--archive' => 'x', '--bytes' => '1', '--sha256' => 'short'])->assertFailed();
        $this->artisan('backup:mark', ['snapshot' => self::SNAP, '--drill' => 'maybe'])->assertFailed();
        $this->artisan('backup:mark', ['snapshot' => self::SNAP])->assertFailed();

        // نسخة بلا صف (أقدم من الجدول أو قبل استعادة): لا يفشل المجمّع بسببها
        $this->artisan('backup:mark', ['snapshot' => self::SNAP, '--pulled' => true])->assertSuccessful();
    }

    public function test_alert_notifies_admins_once_per_window(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->withPermissions(['users.view'])->create();

        $this->artisan('backup:alert', ['message' => 'collect.sh فشل عند: التشفير'])->assertSuccessful();
        $this->artisan('backup:alert', ['message' => 'collect.sh فشل عند: التشفير'])->assertSuccessful();
        $this->artisan('backup:alert', ['message' => 'قرص النسخ ممتلئ'])->assertSuccessful();

        $this->assertSame(2, Notification::count());
        $this->assertSame([$admin->id], Notification::distinct()->pluck('user_id')->all());

        $this->travel(7)->hours();
        $this->artisan('backup:alert', ['message' => 'collect.sh فشل عند: التشفير'])->assertSuccessful();
        $this->assertSame(3, Notification::count());
    }

    public function test_rotation_keeps_gfs_and_recent_events(): void
    {
        $bash = (new ExecutableFinder)->find('bash');
        if (!$bash) {
            $this->markTestSkipped('bash غير متوفر في هذه البيئة (السكربت يعمل على المضيف)');
        }

        $dir = sys_get_temp_dir().'/nj-rotate-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);

        try {
            $day = new \DateTimeImmutable('2026-10-14T02:30:00Z');
            for ($i = 0; $i < 200; $i++) {
                touch($dir.'/nuskjo-'.$day->modify("-{$i} days")->format('Ymd\THis\Z').'-scheduled.tar.gz.age');
            }
            foreach (['20261001T101010Z-pre-migrate', '20260601T101010Z-manual-old', '20261010T090000Z-manual-x'] as $event) {
                touch("{$dir}/nuskjo-{$event}.tar.gz.age");
            }
            for ($i = 1; $i <= 6; $i++) {
                touch("{$dir}/nuskjo-2026100{$i}T120000Z-pre-reset.tar.gz.age");
            }

            $p = new Process([$bash, base_path('scripts/backup/rotate.sh'), "--dir={$dir}", '--dry-run', '--now=2026-10-14T03:00:00Z']);
            $p->mustRun();

            $kept = collect(explode("\n", trim($p->getOutput())))
                ->filter(fn ($l) => str_starts_with($l, 'KEEP '))
                ->map(fn ($l) => substr($l, strlen('KEEP nuskjo-'), 16))
                ->values();

            $expected = [
                // 7 أيام
                '20261014T023000Z', '20261013T023000Z', '20261012T023000Z', '20261011T023000Z',
                '20261010T023000Z', '20261009T023000Z', '20261008T023000Z',
                // أسابيع ISO 40 و39 (42 و41 مغطّاة باليومية)
                '20261004T023000Z', '20260927T023000Z',
                // آخر يوم لكل شهر سابق حتى 6 أشهر
                '20260930T023000Z', '20260831T023000Z', '20260731T023000Z', '20260630T023000Z', '20260531T023000Z',
                // أحداث: أحدث 5 من كل سبب وأقل من 90 يوماً
                '20261010T090000Z', '20261001T101010Z',
                '20261006T120000Z', '20261005T120000Z', '20261004T120000Z', '20261003T120000Z', '20261002T120000Z',
            ];
            $this->assertEqualsCanonicalizing($expected, $kept->all());
            $this->assertCount(209, glob("{$dir}/*.age"), 'الوضع التجريبي لا يحذف شيئاً');
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
