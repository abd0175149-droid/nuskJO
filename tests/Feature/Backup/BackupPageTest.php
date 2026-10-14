<?php

namespace Tests\Feature\Backup;

use App\Console\Commands\BackupSafeMode;
use App\Jobs\RunBackupJob;
use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Services\Backup\DiskUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** صفحة النسخ الاحتياطي، و«نسخة الآن»، واعتماد المراجعة، والمراقب. */
class BackupPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['backup.spool_path' => sys_get_temp_dir().'/nj-page-'.bin2hex(random_bytes(4))]);
        $this->fakeDisk(40.0);
    }

    private function fakeDisk(?float $usedPct): void
    {
        $this->app->instance(DiskUsage::class, new class($usedPct) extends DiskUsage
        {
            public function __construct(private ?float $pct) {}

            public function usedPercent(string $path): ?float
            {
                return $this->pct;
            }

            public function directoryBytes(string $path): int
            {
                return 0;
            }
        });
    }

    private function okRun(int $hoursAgo, array $extra = []): BackupRun
    {
        return BackupRun::create($extra + [
            'reason' => 'scheduled', 'status' => 'ok', 'snapshot_name' => '20261014T023000Z-scheduled',
            'db_bytes' => 1000, 'media_bytes' => 10, 'integrity' => 'ok',
            'started_at' => now()->subHours($hoursAgo), 'finished_at' => now()->subHours($hoursAgo),
        ]);
    }

    private function salesUser(): User
    {
        return User::factory()->withPermissions(['settings.view', 'users.view'])->create();
    }

    public function test_admin_sees_status_without_paths_or_customer_data(): void
    {
        $this->okRun(3, ['collected_at' => now()->subHours(2)]);

        $response = $this->actingAs(User::factory()->admin()->create())->get('/settings/backups');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Backups', false)
            ->where('status.items.snapshot.state', 'ok')
            ->where('status.items.collected.state', 'ok')
            ->where('status.items.pulled.state', 'bad')
            ->where('status.items.disk.used_pct', 40)
            ->has('runs', 1)
            ->where('can.run', true)
        );

        $json = json_encode($response->viewData('page')['props']);
        $this->assertStringNotContainsString(storage_path(), $json);
        $this->assertStringNotContainsString(base_path(), $json);
    }

    public function test_permissions_exist_and_sensitive_ones_are_admin_only_to_grant(): void
    {
        foreach (['backups.view', 'backups.run', 'backups.restore_ack'] as $slug) {
            $this->assertDatabaseHas('permissions', ['slug' => $slug, 'module' => 'backups']);
        }
        $this->assertContains('backups.restore_ack', Permission::SENSITIVE);
    }

    public function test_user_without_backup_permissions_gets_403_everywhere(): void
    {
        $user = $this->salesUser();

        $this->actingAs($user)->get('/settings/backups')->assertForbidden();
        $this->actingAs($user)->post('/settings/backups/run')->assertForbidden();
        $this->actingAs($user)->post('/settings/backups/ack-restore', ['checked' => array_keys(BackupSafeMode::CHECKLIST)])->assertForbidden();
    }

    public function test_run_now_queues_one_job_per_ten_minutes(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/settings/backups/run')->assertSessionHas('success');
        $this->actingAs($admin)->post('/settings/backups/run')->assertSessionHas('error');

        Queue::assertPushed(RunBackupJob::class, 1);
        Queue::assertPushed(RunBackupJob::class, fn ($job) => $job->userId === $admin->id);
    }

    public function test_acknowledge_requires_every_item_then_clears_review_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set(BackupSafeMode::REVIEW_KEY, json_encode(['snapshot' => '20261014T023000Z-scheduled', 'pre_restore' => '20261015T081500Z']));

        $this->actingAs($admin)->post('/settings/backups/ack-restore', ['checked' => ['admin_numbers']])
            ->assertSessionHasErrors('checked');
        $this->assertNotNull(Setting::get(BackupSafeMode::REVIEW_KEY));

        $this->actingAs($admin)->post('/settings/backups/ack-restore', ['checked' => array_keys(BackupSafeMode::CHECKLIST)])
            ->assertSessionHasNoErrors();
        $this->assertNull(Setting::get(BackupSafeMode::REVIEW_KEY));
        $this->assertSame(1, AuditLog::where('action', 'backup.restore_ack')->count());
    }

    public function test_review_flag_is_hidden_from_and_not_writable_through_general_settings(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set(BackupSafeMode::REVIEW_KEY, '{"snapshot":"20261014T023000Z-scheduled"}');

        $this->actingAs($admin)->get('/settings')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('settings', fn ($groups) => !str_contains(json_encode($groups), BackupSafeMode::REVIEW_KEY)));

        $this->actingAs($admin)->put('/settings', ['settings' => [['key' => BackupSafeMode::REVIEW_KEY, 'value' => null]]]);
        $this->assertNotNull(Setting::get(BackupSafeMode::REVIEW_KEY));
    }

    public function test_watchdog_alerts_once_a_day_per_type(): void
    {
        User::factory()->admin()->create();
        $this->okRun(27, ['collected_at' => now()->subHours(2), 'pulled_at' => now()->subDay(), 'drill_summary' => ['result' => 'ok']]);

        $this->artisan('backup:watchdog')->assertSuccessful();
        $this->artisan('backup:watchdog')->assertSuccessful();

        $this->assertSame(1, Notification::count(), 'اللقطة وحدها متأخرة، وتنبيهها مرة واحدة في اليوم');
        $this->assertStringContainsString('لا نسخة احتياطية ناجحة', Notification::first()->body);
    }

    public function test_watchdog_is_quiet_when_everything_is_fresh(): void
    {
        User::factory()->admin()->create();
        $this->okRun(25, ['collected_at' => now()->subHours(2), 'pulled_at' => now()->subDay(), 'drill_summary' => ['result' => 'ok']]);

        $this->artisan('backup:watchdog')->assertSuccessful();

        $this->assertSame(0, Notification::count());
    }

    public function test_watchdog_alerts_on_full_disk(): void
    {
        User::factory()->admin()->create();
        $this->okRun(1, ['collected_at' => now()->subHours(1), 'pulled_at' => now()->subDay(), 'drill_summary' => ['result' => 'ok']]);
        $this->fakeDisk(93.0);

        $this->artisan('backup:watchdog')->assertSuccessful();

        $this->assertSame(1, Notification::count());
        $this->assertStringContainsString('93', Notification::first()->body);
    }
}
