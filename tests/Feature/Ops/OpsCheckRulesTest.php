<?php

namespace Tests\Feature\Ops;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Ops\OpsHealth;
use App\Services\Ops\OpsMetrics;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

/**
 * قواعد whatsapp:ops-check (E24): تنبيه واحد لكل قاعدة في فترتها وإشعار «عاد طبيعياً» مرة، بلا تكرار لما نبّهت عنه
 * آلة صحة القناة (E22)، وأرقام وفئات فقط، ولا نداء لميتا، والرمز 0 دائماً. العلم مطفأ = سجل perf وحده.
 */
class OpsCheckRulesTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        config(['features.env.ops_alerts' => true]);
    }

    private function alerts(?string $title = null): int
    {
        return Notification::where('user_id', $this->admin->id)->when($title, fn ($q) => $q->where('title', $title))->count();
    }

    private function backlog(int $ageSeconds = 400): void
    {
        $now = now()->getTimestamp();
        DB::table('jobs')->insert(['queue' => 'wa-replies', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => $now - $ageSeconds, 'created_at' => $now - $ageSeconds]);
    }

    private function check(): int
    {
        return Artisan::call('whatsapp:ops-check');
    }

    public function test_it_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => $e->description === 'wa:ops-check');

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_queue_backlog_alerts_once_an_hour_and_announces_recovery_once(): void
    {
        $title = config('ops.rules.queue_backlog.title');
        $this->backlog();

        $this->assertSame(0, $this->check());
        $this->assertSame(1, $this->alerts($title));
        $alert = Notification::where('title', $title)->first();
        $this->assertStringContainsString('wa-replies', $alert->body);
        $this->assertSame('/whatsapp/failures#queue', $alert->action_url);

        $this->check();
        $this->assertSame(1, $this->alerts($title), 'لا تكرار خلال الساعة');

        $this->travel(61)->minutes();
        $this->check();
        $this->assertSame(2, $this->alerts($title), 'ما دامت قائمة: مرة كل ساعة');

        DB::table('jobs')->delete();
        $this->check();
        $this->check();
        $this->assertSame(1, $this->alerts('✅ عاد طبيعياً: الطابور متأخر — الردود تتأخر'));
        $this->assertNull(OpsHealth::store()->get('ops:rule:state:queue_backlog'));
    }

    public function test_alerts_use_the_device_push_directly(): void
    {
        $this->backlog();
        $this->check();

        $n = Notification::where('user_id', $this->admin->id)->first();
        $this->assertSame('🩺', $n->icon);
        $this->assertSame('error', $n->type);
    }

    public function test_send_auth_is_skipped_when_channel_health_already_alerted(): void
    {
        config(['features.env.channel_health' => true]);
        WaBotSetting::current();
        DB::table('wa_bot_settings')->update(['wa_health' => 'TOKEN_INVALID']);
        $conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => 'failed', 'error_category' => 'auth', 'meta_code' => 190]);

        $this->check();
        $this->assertSame(0, $this->alerts(config('ops.rules.send_auth.title')));

        DB::table('wa_bot_settings')->update(['wa_health' => 'AVAILABLE']);
        $this->check();
        $this->assertSame(1, $this->alerts(config('ops.rules.send_auth.title')));
    }

    public function test_alert_texts_carry_numbers_and_categories_only(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create(['display_name' => 'عميل اسمه سري', 'needs_attention' => true, 'last_inbound_at' => now()->subHour()]);
        foreach (range(1, 6) as $i) {
            WaConversation::factory()->create(['needs_attention' => true, 'last_inbound_at' => now()->subHour(), 'display_name' => "سري {$i}"]);
        }
        foreach (range(1, 10) as $i) {
            WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => 'failed', 'error_category' => 'window', 'body' => 'نص رسالة العميل']);
        }
        WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => 'unknown', 'body' => 'نص رسالة العميل']);
        $this->backlog();

        $this->check();

        $texts = Notification::where('user_id', $this->admin->id)->get(['title', 'body'])->toJson(JSON_UNESCAPED_UNICODE);
        $this->assertGreaterThanOrEqual(4, $this->alerts());
        foreach (WaConversation::pluck('phone') as $phone) {
            $this->assertStringNotContainsString((string) $phone, $texts);
        }
        $this->assertStringNotContainsString('سري', $texts);
        $this->assertStringNotContainsString('نص رسالة العميل', $texts);
        $this->assertStringContainsString('النافذة 10', $texts);
    }

    public function test_daily_outbound_uses_the_setting_and_alerts_once_a_day(): void
    {
        WaBotSetting::current();
        DB::table('wa_bot_settings')->update(['wa_daily_alert_threshold' => 2]);
        $conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->outbound()->count(3)->create(['conversation_id' => $conv->id]);
        $title = config('ops.rules.daily_outbound.title');

        $this->check();
        $this->travel(2)->hours();
        $this->check();

        $this->assertSame(1, $this->alerts($title));
    }

    public function test_automated_sources_over_their_budget_alert_by_kind(): void
    {
        config(['ops.automated_budgets' => ['outage' => 1, 'reply' => null]]);
        $conv = WaConversation::factory()->windowOpen()->create();
        foreach (['outage:1:1', 'outage:1:2', 'reply:1:3:text'] as $key) {
            WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'source' => 'system', 'send_key' => $key]);
        }

        $this->check();

        $n = Notification::where('title', config('ops.rules.automated_budget.title'))->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('outage 2/1', $n->body);
    }

    public function test_log_mode_records_transitions_without_notifying(): void
    {
        config(['features.env.ops_alerts' => false]);
        $this->captureLogs();
        $this->backlog();

        $this->check();

        $this->assertSame(0, $this->alerts());
        $this->assertLogged('warning', 'ops_rule', ['rule' => 'queue_backlog', 'state' => 'firing', 'value' => 400, 'threshold' => 300, 'mode' => 'log']);
        $this->assertNotNull(OpsMetrics::last(), 'اللقطة تُحفظ في الوضعين');
    }

    public function test_it_never_calls_meta_and_exits_zero_even_on_internal_errors(): void
    {
        Http::fake();
        $this->backlog();

        $this->assertSame(0, $this->check());
        Http::assertNothingSent();

        // مخزن ops غير معرّف: الحفظ يرمي، والأمر يكتب السبب ويخرج بالصفر
        config(['ops.cache_store' => 'missing-store']);
        $this->assertSame(0, Artisan::call('whatsapp:ops-check'));
        $this->assertStringContainsString('تعذّر الفحص', Artisan::output());
    }

    public function test_the_snapshot_is_cached_for_the_metrics_route(): void
    {
        $this->check();

        $snap = OpsMetrics::last();
        $this->assertIsArray($snap);
        $this->assertArrayHasKey('queue', $snap);
        $this->assertNotNull(OpsHealth::store()->get(OpsMetrics::HOURLY_KEY.now()->format('YmdH')));
    }
}
