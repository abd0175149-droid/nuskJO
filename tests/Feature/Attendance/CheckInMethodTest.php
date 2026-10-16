<?php

namespace Tests\Feature\Attendance;

use App\Enums\AuditAction;
use App\Models\Attendance;
use App\Models\AttendanceLocation;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تسجيل الحضور (E27): الخادم يقبل ip أو geo وحدهما لأنه يتحقق منهما، والتعديل اليدوي بصلاحيته ويُدقَّق.
 */
class CheckInMethodTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): User
    {
        $user = User::factory()->create();
        Employee::create(['employee_number' => 'E-'.$user->id, 'user_id' => $user->id, 'hire_date' => now()->subYear()->toDateString()]);
        AttendanceLocation::create(['name' => 'المكتب', 'type' => 'geo', 'latitude' => 31.9539, 'longitude' => 35.9106, 'radius_meters' => 200, 'is_active' => true]);

        return $user;
    }

    public function test_unverified_methods_are_refused(): void
    {
        $user = $this->employee();

        foreach (['webauthn', 'manual'] as $method) {
            $this->actingAs($user)->postJson('/attendance/check-in', ['method' => $method])->assertStatus(422)->assertJsonValidationErrors('method');
            $this->actingAs($user)->postJson('/attendance/check-out', ['method' => $method])->assertStatus(422)->assertJsonValidationErrors('method');
        }
        $this->assertSame(0, Attendance::count());
    }

    public function test_geo_at_an_approved_location_works_as_before(): void
    {
        $user = $this->employee();

        $this->actingAs($user)->postJson('/attendance/check-in', ['method' => 'geo', 'lat' => 31.9540, 'lng' => 35.9107])->assertOk();
        $this->actingAs($user)->postJson('/attendance/check-in', ['method' => 'geo', 'lat' => 32.5, 'lng' => 36.5])->assertForbidden();
        $this->assertSame('geo', Attendance::sole()->check_in_method);
    }

    public function test_manual_edit_stays_behind_its_permission_and_is_audited(): void
    {
        $user = $this->employee();
        $this->actingAs($user)->postJson('/attendance/check-in', ['method' => 'geo', 'lat' => 31.9540, 'lng' => 35.9107])->assertOk();
        $attendance = Attendance::sole();

        $this->actingAs($user)->post("/attendance/{$attendance->id}/manual-edit", ['check_in' => '08:00', 'status' => 'present'])->assertForbidden();

        $hr = User::factory()->withPermissions(['attendance.manual_edit'], 'hr_manager')->create();
        $this->actingAs($hr)->post("/attendance/{$attendance->id}/manual-edit", ['check_in' => '08:00', 'status' => 'present'])->assertRedirect();

        $row = AuditLog::where('action', AuditAction::Update->value)->where('entity_type', 'attendance')->sole();
        $this->assertTrue($row->changes['manual']);
        $this->assertSame('08:00', $row->changes['after']['check_in']);
    }
}
