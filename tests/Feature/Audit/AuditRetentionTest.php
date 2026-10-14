<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الاحتفاظ (E09.13): التقليم يحذف الضجيج القديم وحده ويترك أثراً، والتفريغ لا يمسح السجل بل يُسجَّل فيه.
 */
class AuditRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function aged(AuditAction $action, int $days): int
    {
        $row = AuditLog::log($action, 'User', 1);
        // الجدول للإلحاق فقط: التقادم يُحاكى بإدخال مباشر بتاريخ قديم
        $old = $row->replicate()->forceFill(['created_at' => now()->subDays($days)])->getAttributes();
        DB::table('audit_logs')->where('id', $row->id)->delete();

        return DB::table('audit_logs')->insertGetId(['changes' => null] + $old);
    }

    public function test_pruning_removes_only_old_noise_and_records_how_many(): void
    {
        config(['audit.extended' => true]);
        $oldNoise = $this->aged(AuditAction::AuthLoginFailed, 120);
        $oldView = $this->aged(AuditAction::WaConversationView, 91);
        $recentNoise = $this->aged(AuditAction::AuthLogin, 10);
        $oldMoney = $this->aged(AuditAction::Approve, 400);
        $oldAccess = $this->aged(AuditAction::UserRoleChange, 400);

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

        $left = AuditLog::pluck('id')->all();
        $this->assertNotContains($oldNoise, $left);
        $this->assertNotContains($oldView, $left);
        foreach ([$recentNoise, $oldMoney, $oldAccess] as $kept) {
            $this->assertContains($kept, $left);
        }
        $pruned = AuditLog::where('action', AuditAction::AuditPruned->value)->sole();
        $this->assertSame(2, $pruned->changes['count']);
        $this->assertSame(['system', 'artisan:model:prune'], [$pruned->actor_type, $pruned->actor_ref]);
    }

    public function test_reset_keeps_the_audit_log_and_records_itself_first(): void
    {
        $this->app->make(Kernel::class)->registerCommand(new class extends Command
        {
            protected $signature = 'backup:run {--reason=} {--label=}';

            public function handle(): int
            {
                return 0;
            }
        });
        $before = AuditLog::log(AuditAction::Approve, 'invoice', 1, 'INV-1');

        $this->artisan('app:reset-data', ['--confirm' => true])->assertSuccessful();

        $this->assertNotNull(AuditLog::find($before->id), 'التفريغ لا يمسح سجل التدقيق');
        $reset = AuditLog::where('action', AuditAction::SystemReset->value)->sole();
        $this->assertSame(['system', 'artisan:app:reset-data', 'error'], [$reset->actor_type, $reset->actor_ref, $reset->severity]);
        $this->assertNotContains('audit_logs', $reset->changes['tables']);
        $this->assertContains('invoices', $reset->changes['tables']);
    }

    public function test_a_refused_reset_leaves_no_reset_row(): void
    {
        $this->artisan('app:reset-data', ['--confirm' => true])->assertFailed();

        $this->assertSame(0, AuditLog::where('action', AuditAction::SystemReset->value)->count());
    }
}
