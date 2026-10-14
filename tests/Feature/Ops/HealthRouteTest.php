<?php

namespace Tests\Feature\Ops;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\Notification;
use App\Models\User;
use App\Services\Ops\OpsHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

/**
 * /up يفشل حقاً: قاعدة، أو مجدول بلا نبضة 3 دقائق، أو عامل بلا نبضة 5 دقائق، أو وظيفة لم يلتقطها أحد.
 * بلا تفاصيل في الرد، وبلا نداء لميتا، وسطر سجل واحد لكل رمز في الساعة.
 */
class HealthRouteTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'ops.health_checks' => true]);
        Http::fake();
        $this->beat();
    }

    /** نبضات حديثة لكل شيء، أو لما يُمرَّر فقط */
    private function beat(?array $keys = null): void
    {
        $keys ??= array_merge([OpsHealth::SCHEDULE_BEAT], array_map(OpsHealth::beatKey(...), OpsHealth::queues()));
        foreach ($keys as $key) {
            Cache::put($key, now()->getTimestamp());
        }
    }

    private function upJson()
    {
        // نتيجة الفحص محفوظة 10 ثوانٍ
        OpsHealth::store()->forget('ops:health:diagnosis');

        return $this->getJson('/up');
    }

    public function test_a_healthy_system_is_up(): void
    {
        $this->upJson()->assertOk()->assertExactJson(['status' => 'up']);
        $this->get('/up')->assertOk()->assertSee('Application up');
    }

    public static function failures(): array
    {
        return [
            'scheduler silent for 4 minutes' => [fn () => null, 'schedule_stale'],
            'default worker silent' => [fn () => Cache::put(OpsHealth::SCHEDULE_BEAT, now()->getTimestamp()) ?: Cache::put(OpsHealth::beatKey(ProcessWhatsAppReply::queueName()), now()->getTimestamp()), 'queue_stale'],
            'reply worker silent' => [fn () => Cache::put(OpsHealth::SCHEDULE_BEAT, now()->getTimestamp()) ?: Cache::put(OpsHealth::beatKey('default'), now()->getTimestamp()), 'reply_queue_stale'],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_silent_component_takes_up_down_without_details(\Closure $refresh, string $code): void
    {
        $this->travel(6)->minutes();
        $refresh();

        $response = $this->upJson();

        $response->assertStatus(500)->assertExactJson(['status' => 'down']);
        $this->assertContains($code, app(OpsHealth::class)->diagnose());
        $this->assertStringNotContainsString($code, $this->get('/up')->getContent());
    }

    public function test_a_job_nobody_picked_up_for_five_minutes_is_a_backlog(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(6)->getTimestamp(), 'created_at' => now()->subMinutes(6)->getTimestamp()]);

        $this->upJson()->assertStatus(500);
        $this->assertSame(['backlog'], app(OpsHealth::class)->diagnose());
    }

    public function test_repeated_failures_log_one_line_per_code_and_report_nothing(): void
    {
        $this->captureLogs();
        $this->travel(6)->minutes();

        for ($i = 0; $i < 20; $i++) {
            $this->upJson()->assertStatus(500);
        }

        $lines = collect($this->logged)->where('message', 'ops.health_failed');
        $this->assertSame(['schedule_stale', 'queue_stale', 'reply_queue_stale'], $lines->pluck('context.code')->all());
        // report() كان يكتب سطراً لكل طلب برسالة الرموز
        $this->assertCount($lines->count(), $this->logged);
    }

    public function test_admins_are_alerted_once_an_hour_per_code_after_the_response(): void
    {
        $admin = User::factory()->admin()->create();
        $this->travel(6)->minutes();
        $this->beat([OpsHealth::SCHEDULE_BEAT, OpsHealth::beatKey(ProcessWhatsAppReply::queueName())]);

        $this->upJson()->assertStatus(500);
        $this->upJson()->assertStatus(500);

        $this->assertSame(['⛔ العامل الخلفي متوقف'], Notification::where('user_id', $admin->id)->pluck('title')->all());
    }

    public function test_up_never_calls_meta(): void
    {
        $this->upJson()->assertOk();
        $this->travel(6)->minutes();
        $this->upJson()->assertStatus(500);

        Http::assertNothingSent();
    }

    public function test_switching_the_checks_off_keeps_up_green(): void
    {
        config(['ops.health_checks' => false]);
        $this->travel(30)->minutes();

        $this->upJson()->assertOk()->assertExactJson(['status' => 'up']);
    }

    public function test_the_route_is_outside_the_web_group(): void
    {
        $response = $this->get('/up');

        $this->assertSame([], $response->headers->getCookies());
    }
}
