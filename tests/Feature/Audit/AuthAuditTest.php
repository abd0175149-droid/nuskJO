<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أحداث الدخول (E09.10): الفشل بالعيّنات ببريد مقنّع، والدخول والخروج صفوف، والمعطّل صف حظر لا دخول،
 * والقفل يُكتب حتى والعلم مطفأ (كما في E03).
 */
class AuthAuditTest extends TestCase
{
    use RefreshDatabase;

    private function rows(AuditAction $action)
    {
        return AuditLog::where('action', $action->value)->orderBy('id')->get();
    }

    public function test_a_failed_login_is_sampled_with_a_masked_email_and_no_password(): void
    {
        config(['audit.extended' => true]);
        $user = User::factory()->create(['email' => 'victim@example.test']);

        $this->post('/login', ['email' => 'victim@example.test', 'password' => 'Wr0ngPassw0rd!'])->assertSessionHasErrors('email');

        $row = $this->rows(AuditAction::AuthLoginFailed)->sole();
        $this->assertSame('v***@example.test', $row->entity_label);
        $this->assertSame($user->id, $row->entity_id);
        $this->assertNull($row->user_id);
        $dump = json_encode($row->toArray());
        $this->assertStringNotContainsString('Wr0ngPassw0rd', $dump);
        $this->assertStringNotContainsString('victim@', $dump);
    }

    public function test_login_and_logout_are_recorded_for_the_user(): void
    {
        config(['audit.extended' => true]);
        $user = User::factory()->create(['email' => 'staff@example.test']);

        $this->post('/login', ['email' => 'staff@example.test', 'password' => 'password'])->assertRedirect('/');
        $this->post('/logout')->assertRedirect('/login');

        $login = $this->rows(AuditAction::AuthLogin)->sole();
        $this->assertSame([$user->id, $user->id, 'user'], [$login->user_id, $login->entity_id, $login->actor_type]);
        $this->assertSame($user->id, $this->rows(AuditAction::AuthLogout)->sole()->user_id);
    }

    public function test_an_inactive_account_gets_a_blocked_row_and_no_login_or_logout_rows(): void
    {
        config(['audit.extended' => true]);
        $user = User::factory()->inactive()->create(['email' => 'gone@example.test']);

        $this->post('/login', ['email' => 'gone@example.test', 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertSame($user->id, $this->rows(AuditAction::AuthInactiveBlocked)->sole()->entity_id);
        $this->assertCount(0, $this->rows(AuditAction::AuthLogin));
        $this->assertCount(0, $this->rows(AuditAction::AuthLogout));
        $this->assertGuest();
    }

    public function test_without_the_flag_only_lockouts_are_written_and_they_are_sampled(): void
    {
        User::factory()->create(['email' => 'victim@example.test']);

        foreach (range(1, 20) as $_) {
            $this->post('/login', ['email' => 'victim@example.test', 'password' => 'wrong']);
        }

        $this->assertCount(0, $this->rows(AuditAction::AuthLoginFailed));
        $lockouts = $this->rows(AuditAction::AuthLockout);
        $this->assertSame((int) config('audit.sample_per_min'), $lockouts->count(), '15 قفلاً في الدقيقة، والعيّنة 10');
        $this->assertSame('warn', $lockouts->first()->severity);
    }
}
