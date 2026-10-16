<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EnrollsSecondFactor;
use Tests\TestCase;

/**
 * لا يغادر الخادم سر التحقق بخطوتين ولا رموزه ولا آخر خطوة (E27). قائمة المستخدمين تحمل حقولاً محسوبة فقط،
 * وآخر عنوان دخول لمن يملك تعديل المستخدمين وحده.
 */
class UserSecretsHiddenTest extends TestCase
{
    use EnrollsSecondFactor, RefreshDatabase;

    public function test_users_page_carries_computed_fields_only(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->withPermissions(['invoices.approve'])->create(['last_login_ip' => '203.0.113.7']);
        $this->enroll($employee);
        $employee->forceFill(['last_login_ip' => '203.0.113.7'])->save();

        $response = $this->actingAs($admin)->get('/users')->assertOk();
        $page = $response->getContent();
        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step', 'two_factor_confirmed_at', self::TEST_TOTP_SECRET, 'remember_token'] as $needle) {
            $this->assertStringNotContainsString($needle, $page);
        }

        $response->assertInertia(fn ($p) => $p->where('users.data', fn ($rows) => collect($rows)->contains(
            fn ($r) => $r['id'] === $employee->id && $r['has_mfa'] === true && $r['requires_mfa'] === true && $r['last_login_ip'] === '203.0.113.7')));
    }

    public function test_the_login_address_is_hidden_from_viewers_without_users_update(): void
    {
        $viewer = User::factory()->withPermissions(['users.view'])->create();
        User::factory()->create()->forceFill(['last_login_ip' => '203.0.113.9'])->save();

        $response = $this->actingAs($viewer)->get('/users')->assertOk();

        $this->assertStringNotContainsString('203.0.113.9', $response->getContent());
    }

    public function test_the_filters_find_idle_accounts_and_missing_second_factors(): void
    {
        $admin = User::factory()->admin()->create();
        $idle = User::factory()->create(['name' => 'موظف خامل']);
        $idle->forceFill(['last_seen_at' => now()->subDays(61), 'created_at' => now()->subYear()])->save();
        $fresh = User::factory()->create(['name' => 'موظف نشط']);
        $fresh->forceFill(['last_seen_at' => now()->subDay()])->save();
        $approver = User::factory()->withPermissions(['receipts.approve'], 'accountant')->create(['name' => 'محاسب بلا تحقق']);

        $this->actingAs($admin)->get('/users?filter=idle')->assertInertia(fn ($p) => $p
            ->where('users.data', fn ($rows) => collect($rows)->pluck('name')->contains('موظف خامل') && !collect($rows)->pluck('name')->contains('موظف نشط')));

        $this->actingAs($admin)->get('/users?filter=mfa_missing')->assertInertia(fn ($p) => $p
            ->where('users.data', fn ($rows) => collect($rows)->pluck('name')->contains('محاسب بلا تحقق') && !collect($rows)->pluck('name')->contains('موظف نشط')));
    }
}
