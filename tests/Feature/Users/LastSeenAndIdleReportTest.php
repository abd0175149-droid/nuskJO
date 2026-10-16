<?php

namespace Tests\Feature\Users;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaAdminNumber;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * آخر نشاط وتقرير الخمول (E27): last_seen_at يُنسخ من جدول الجلسات باستعلام واحد ولا يرجع للخلف، والتقرير الأسبوعي
 * إشعار واحد لكل مدير نشط بأسماء الخاملين، بلا أي تعطيل آلي.
 */
class LastSeenAndIdleReportTest extends TestCase
{
    use RefreshDatabase;

    private function sessionRow(User $user, int $lastActivity, string $id): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => $lastActivity]);
    }

    public function test_last_seen_is_copied_from_the_newest_session_and_never_moves_back(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $none = User::factory()->create();
        $this->sessionRow($a, 1_700_000_000, 'a1');
        $this->sessionRow($a, 1_700_000_600, 'a2');
        $this->sessionRow($b, 1_700_000_300, 'b1');

        $this->artisan('users:sync-last-seen')->assertSuccessful();

        $this->assertSame('2023-11-14 22:23:20', $a->fresh()->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2023-11-14 22:18:20', $b->fresh()->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertNull($none->fresh()->last_seen_at);

        // جلسة أقدم من المحفوظ لا ترجعه للخلف
        $b->forceFill(['last_seen_at' => '2024-01-01 00:00:00'])->save();
        $this->artisan('users:sync-last-seen')->assertSuccessful();
        $this->assertSame('2024-01-01 00:00:00', $b->fresh()->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_the_weekly_report_names_idle_accounts_and_numbers_and_disables_nobody(): void
    {
        config(['features.env.idle_report' => true]);
        $admin = User::factory()->admin()->create(['name' => 'المدير']);
        $admin->forceFill(['last_seen_at' => now()])->save();
        $inactiveAdmin = User::factory()->admin()->inactive()->create();
        $idle = User::factory()->create(['name' => 'خالد الخامل']);
        $idle->forceFill(['last_seen_at' => now()->subDays(70)])->save();
        $neverSeen = User::factory()->create(['name' => 'حساب قديم']);
        $neverSeen->forceFill(['created_at' => now()->subDays(90)])->save();
        $recent = User::factory()->create(['name' => 'سارة النشطة']);
        $recent->forceFill(['last_seen_at' => now()->subDays(3)])->save();
        WaAdminNumber::factory()->create(['name' => 'رقم المحاسب', 'last_used_at' => now()->subDays(120)]);
        WaAdminNumber::factory()->create(['name' => 'رقم المدير', 'last_used_at' => now()->subDay()]);
        User::factory()->withPermissions(['invoices.approve'], 'accountant')->create()->forceFill(['last_seen_at' => now()])->save();

        $this->travelTo(now()->next('Sunday')->setTime(5, 47));
        $this->artisan('users:idle-report')->assertSuccessful();

        $sent = Notification::where('user_id', $admin->id)->sole();
        $this->assertStringContainsString('خالد الخامل', $sent->body);
        $this->assertStringContainsString('حساب قديم', $sent->body);
        $this->assertStringNotContainsString('سارة النشطة', $sent->body);
        $this->assertStringContainsString('رقم المحاسب', $sent->body);
        $this->assertStringNotContainsString('رقم المدير', $sent->body);
        $this->assertStringContainsString('بلا تحقق بخطوتين: 2', $sent->body, 'المدير والمحاسب يتطلبانه');
        $this->assertSame('/users?filter=idle', $sent->action_url);
        $this->assertSame(0, Notification::where('user_id', $inactiveAdmin->id)->count());
        $this->assertTrue($idle->fresh()->is_active, 'لا تعطيل آلي');
    }

    public function test_with_the_flag_off_the_report_prints_but_sends_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create()->forceFill(['last_seen_at' => now()->subDays(80)])->save();

        $this->artisan('users:idle-report')->expectsOutputToContain('idle_report مطفأ')->assertSuccessful();
        $this->assertSame(0, Notification::where('user_id', $admin->id)->count());
    }

    public function test_both_commands_are_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => [$e->command, $e->expression, $e->timezone]);

        $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e[0], 'users:sync-last-seen') && $e[1] === '*/15 * * * *'));
        $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e[0], 'users:idle-report') && $e[1] === '47 8 * * 0' && (string) $e[2] === 'Asia/Amman'));
    }
}
