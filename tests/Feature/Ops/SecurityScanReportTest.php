<?php

namespace Tests\Feature\Ops;

use App\Models\Notification;
use App\Models\User;
use App\Services\Ops\SecurityScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * security:scan-report (E26.11): أعداد الفحص الأسبوعي فقط في مخزن التشغيل، وتنبيه المدراء عند ثغرة حرجة أو زيادة
 * العالية عن الفحص السابق، وسطر «آخر فحص للصورة» في بطاقة الصحة لمن يملك إعدادات الواتساب.
 */
class SecurityScanReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_are_recorded_and_admins_alerted_on_critical_or_a_rise(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertSame(0, Artisan::call('security:scan-report', ['--high' => '3', '--critical' => '0']));
        $this->assertSame(0, Notification::where('user_id', $admin->id)->count(), 'أول فحص بلا حرجة: لا تنبيه');
        $this->assertSame(['scanner' => 'trivy', 'high' => 3, 'critical' => 0, 'previous_high' => null], array_diff_key(SecurityScan::summary(), ['at' => 1]));

        $this->assertSame(0, Artisan::call('security:scan-report', ['--high' => '3', '--critical' => '0']));
        $this->assertSame(0, Notification::where('user_id', $admin->id)->count(), 'العالية لم تزد');

        $this->assertSame(0, Artisan::call('security:scan-report', ['--high' => '5', '--critical' => '0']));
        $alert = Notification::where('user_id', $admin->id)->sole();
        $this->assertStringContainsString('عالية 5 (كانت 3)', $alert->body);
        $this->assertSame(3, SecurityScan::summary()['previous_high']);
    }

    public function test_a_critical_finding_always_alerts(): void
    {
        $admin = User::factory()->admin()->create();

        Artisan::call('security:scan-report', ['--high' => '0', '--critical' => '2', '--scanner' => 'trivy']);

        $this->assertStringContainsString('حرجة 2', Notification::where('user_id', $admin->id)->sole()->body);
    }

    public function test_bad_input_is_refused_without_recording(): void
    {
        $this->assertSame(2, Artisan::call('security:scan-report', ['--high' => 'many', '--critical' => '0']));
        $this->assertSame(2, Artisan::call('security:scan-report', ['--high' => '1']));
        $this->assertSame(2, Artisan::call('security:scan-report', ['--high' => '1', '--critical' => '0', '--scanner' => 'x; rm']));
        $this->assertNull(SecurityScan::summary());
    }

    public function test_the_health_card_gets_the_date_and_counts_only(): void
    {
        SecurityScan::record('trivy', 4, 1);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/whatsapp/settings')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('securityScan.high', 4)
                ->where('securityScan.critical', 1)
                ->where('securityScan.scanner', 'trivy')
                ->has('securityScan', 5));
    }
}
