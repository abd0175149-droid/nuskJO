<?php

namespace Tests\Feature\Retention;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaAdminAction;
use App\Models\WaAdminNumber;
use App\Models\WaBotUsage;
use App\Services\WhatsApp\BotAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** تقليم الجداول الجانبية (E13.7): الإشعارات والمساعد الإداري واستهلاك البوت بعد تجميعه، دون تغيير الإجماليات */
class PrunableModelsTest extends TestCase
{
    use RefreshDatabase;

    private function prune(string $model): void
    {
        Artisan::call('model:prune', ['--model' => [$model]]);
    }

    public function test_read_notifications_go_after_90_days_and_unread_after_180(): void
    {
        $user = User::factory()->create();
        $read = Notification::create(['user_id' => $user->id, 'title' => 'a', 'body' => 'b', 'type' => 't', 'is_read' => true]);
        $unread = Notification::create(['user_id' => $user->id, 'title' => 'a', 'body' => 'b', 'type' => 't']);
        $ancient = Notification::create(['user_id' => $user->id, 'title' => 'a', 'body' => 'b', 'type' => 't']);
        Notification::whereKey([$read->id])->update(['created_at' => now()->subDays(91)]);
        Notification::whereKey([$unread->id])->update(['created_at' => now()->subDays(120)]);
        Notification::whereKey([$ancient->id])->update(['created_at' => now()->subDays(181)]);

        $this->prune(Notification::class);

        $this->assertSame([$unread->id], Notification::pluck('id')->all());
    }

    public function test_admin_reads_go_after_180_days_and_executed_writes_stay_two_years(): void
    {
        $n = WaAdminNumber::factory()->create();
        $read = WaAdminAction::create(['admin_number_id' => $n->id, 'tool' => 'find_client', 'action' => 'search', 'status' => 'done', 'created_at' => now()->subDays(200)]);
        $write = WaAdminAction::create(['admin_number_id' => $n->id, 'tool' => 'manage_knowledge', 'action' => 'add', 'status' => 'done', 'created_at' => now()->subDays(200)]);
        $preview = WaAdminAction::create(['admin_number_id' => $n->id, 'tool' => 'manage_knowledge', 'action' => 'add', 'status' => 'preview', 'created_at' => now()->subDays(200)]);
        $oldWrite = WaAdminAction::create(['admin_number_id' => $n->id, 'tool' => 'set_customer_bot', 'action' => 'off', 'status' => 'done', 'created_at' => now()->subDays(731)]);

        $this->prune(WaAdminAction::class);

        $this->assertSame([$write->id], WaAdminAction::pluck('id')->all());
    }

    public function test_bot_usage_is_pruned_only_after_rollup_and_totals_stay_the_same(): void
    {
        foreach ([now()->subMonths(13), now()->subMonths(13)->addDays(2), now()->subDays(3)] as $at) {
            WaBotUsage::create(['source' => 'live', 'model' => 'gemini-test', 'calls' => 1, 'prompt_tokens' => 1000, 'output_tokens' => 500,
                'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'tools_used' => [], 'leaked' => false, 'latency_ms' => 10, 'created_at' => $at]);
        }
        $before = BotAnalytics::usage()['tiles'];

        $this->prune(WaBotUsage::class);
        $this->assertSame(3, WaBotUsage::count(), 'لم يُجمَّع بعد: لا يُحذف');

        Artisan::call('whatsapp:usage-rollup');
        $this->prune(WaBotUsage::class);
        $this->assertSame(1, WaBotUsage::count());

        $after = BotAnalytics::usage()['tiles'];
        foreach (['cost_total', 'replies', 'tokens_in', 'tokens_out'] as $k) {
            $this->assertEquals($before[$k], $after[$k], $k);
        }
        $this->assertSame(1, $after['rolled_up_months']);
    }
}
