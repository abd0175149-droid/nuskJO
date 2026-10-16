<?php

namespace Tests\Feature\Ops;

use App\Console\Commands\OpsTick;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * ميزانية الحمل على الخادم المشترك (E24، docs/ops/load-budget.md): عمّال الطابور ضمن الحد وبـ sleep لا يقل عن 3 ثوانٍ،
 * ومهام الدقيقة ضمن الحد، وكل مهمة مجدولة مذكورة في القائمة الموحدة. مهمة أو عامل جديد يُضاف إليها في التغيير نفسه.
 */
class LoadBudgetTest extends TestCase
{
    private function doc(): string
    {
        return (string) file_get_contents(base_path('docs/ops/load-budget.md'));
    }

    /** اسم المهمة: أمر artisan، أو الاسم المعطى لنداء أو وظيفة في المجدول */
    private function identifier(Event $e): string
    {
        if ($e->command && preg_match("/artisan'?\\s+'?([a-z0-9:_\\-]+)/", $e->command, $m)) {
            return $m[1];
        }

        return (string) $e->description;
    }

    /** @return list<array{program: string, numprocs: int, sleep: ?int}> */
    private function workers(): array
    {
        $ini = parse_ini_file(base_path('docker/supervisord.conf'), true, INI_SCANNER_RAW) ?: [];
        $out = [];
        foreach ($ini as $section => $values) {
            if (!str_starts_with((string) $section, 'program:') || !str_contains((string) ($values['command'] ?? ''), 'queue:work')) {
                continue;
            }
            $out[] = [
                'program' => substr((string) $section, 8),
                'numprocs' => (int) ($values['numprocs'] ?? 1),
                'sleep' => preg_match('/--sleep=(\d+)/', (string) $values['command'], $m) ? (int) $m[1] : null,
            ];
        }

        return $out;
    }

    public function test_queue_workers_stay_within_the_budget_and_sleep_at_least_three_seconds(): void
    {
        $workers = $this->workers();
        $this->assertNotEmpty($workers, 'لا يجد الحارس عمّال الطابور');

        $this->assertLessThanOrEqual(config('ops.load_budget.max_workers'), array_sum(array_column($workers, 'numprocs')), 'عمّال أكثر من الميزانية');
        foreach ($workers as $w) {
            $this->assertNotNull($w['sleep'], "{$w['program']} بلا --sleep");
            $this->assertGreaterThanOrEqual(config('ops.load_budget.min_sleep'), $w['sleep'], "{$w['program']}: sleep أقل من الميزانية");
            $this->assertStringContainsString("`{$w['program']}`", $this->doc(), "{$w['program']} غير مذكور في docs/ops/load-budget.md");
        }
    }

    public function test_every_scheduled_task_is_in_the_unified_list(): void
    {
        $doc = $this->doc();
        $missing = [];
        foreach (app(Schedule::class)->events() as $event) {
            $id = $this->identifier($event);
            if (!str_contains($doc, "`{$id}`")) {
                $missing[] = $id;
            }
        }

        $this->assertSame([], $missing, 'مهام مجدولة غير مذكورة في القائمة الموحدة docs/ops/load-budget.md');
    }

    public function test_per_minute_tasks_stay_within_the_budget(): void
    {
        $perMinute = array_filter(app(Schedule::class)->events(), fn (Event $e) => $e->expression === '* * * * *');

        $this->assertLessThanOrEqual(config('ops.load_budget.max_per_minute'), count($perMinute),
            'مهام الدقيقة: '.implode('، ', array_map(fn ($e) => $this->identifier($e), $perMinute)));
    }

    public function test_ops_tick_replaces_the_per_minute_commands_only_with_its_flag(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn (Event $e) => $this->identifier($e));
        $app = app();

        config(['features.env.ops_tick' => false]);
        $this->assertFalse($events['ops:tick']->filtersPass($app));
        $this->assertTrue($events['whatsapp:replay-webhooks']->filtersPass($app));
        $this->assertTrue($events['whatsapp:reconcile-replies']->filtersPass($app));

        config(['features.env.ops_tick' => true]);
        $this->assertTrue($events['ops:tick']->filtersPass($app));
        $this->assertFalse($events['whatsapp:replay-webhooks']->filtersPass($app));
        $this->assertFalse($events['whatsapp:reconcile-replies']->filtersPass($app));
        $this->assertSame(OpsTick::COMMANDS, ['whatsapp:replay-webhooks', 'whatsapp:reconcile-replies']);
    }
}
