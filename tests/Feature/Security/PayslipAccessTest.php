<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** القسيمة لصاحبها أو لمن يملك payroll.view فقط. */
class PayslipAccessTest extends TestCase
{
    use RefreshDatabase;

    private function employeeFor(User $user): int
    {
        return DB::table('employees')->insertGetId([
            'employee_number' => 'E-'.$user->id,
            'user_id' => $user->id,
            'hire_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_account_without_employee_record_cannot_open_someone_elses_payslip(): void
    {
        $employee = $this->employeeFor(User::factory()->create());
        $outsider = User::factory()->withPermissions(['clients.view'])->create();

        $this->actingAs($outsider)->get("/payslip/{$employee}/9/2026")->assertForbidden();
    }

    public function test_employee_cannot_open_a_colleagues_payslip(): void
    {
        $colleague = $this->employeeFor(User::factory()->create());
        $me = User::factory()->withPermissions(['clients.view'])->create();
        $this->employeeFor($me);

        $this->actingAs($me)->get("/payslip/{$colleague}/9/2026")->assertForbidden();
    }

    public function test_own_payslip_and_payroll_viewers_are_allowed(): void
    {
        $me = User::factory()->withPermissions(['clients.view'])->create();
        $mine = $this->employeeFor($me);
        $hr = User::factory()->withPermissions(['payroll.view'], 'hr_manager')->create();

        // لا قسيمة معتمدة للفترة: رجوع برسالة، لا 403
        $this->actingAs($me)->get("/payslip/{$mine}/9/2026")->assertRedirect();
        $this->actingAs($hr)->get("/payslip/{$mine}/9/2026")->assertRedirect();
    }
}
