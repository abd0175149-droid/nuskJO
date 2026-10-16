<?php

namespace Tests\Feature\Docs;

use App\Support\Ops\RunbookMap;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * كل مهمة مجدولة لها كتيّب عند فشلها (E25): القائمة تُشتق من المجدول نفسه بعد الإقلاع، لا من قائمة مكتوبة.
 * الاسم كما في القائمة الموحدة docs/ops/load-budget.md (أمر artisan، أو ->name() لنداء أو وظيفة)، والكتيّب في
 * config/runbooks.php تحت schedules، ومرساته في docs/RUNBOOK.md.
 */
class RunbookScheduleCoverageTest extends TestCase
{
    private function runbook(): string
    {
        return (string) file_get_contents(base_path('docs/RUNBOOK.md'));
    }

    /** اسم المهمة بالطريقة نفسها في LoadBudgetTest */
    public static function identifier(Event $event): string
    {
        if ($event->command && preg_match("/artisan'?\\s+'?([a-z0-9:_\\-]+)/", $event->command, $m)) {
            return $m[1];
        }

        return trim((string) $event->description);
    }

    public function test_every_scheduled_task_has_a_named_runbook(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertNotEmpty($events, 'المجدول بلا مهام: routes/console.php لم يُحمَّل');

        $problems = [];
        foreach ($events as $event) {
            $id = self::identifier($event);
            if ($id === '') {
                $problems[] = "حدث مجدول بلا اسم ({$event->expression}): أضف ->name('…') في routes/console.php، فالحدث المجهول لا يُوثَّق";

                continue;
            }
            $rb = RunbookMap::forSchedule($id);
            if ($rb === null) {
                $problems[] = "المهمة المجدولة «{$id}» بلا كتيّب: أضف سطر '{$id}' => 'RB-NN' في config/runbooks.php تحت schedules، وصفها في docs/ops/load-budget.md";
            } elseif (!str_contains($this->runbook(), '<a id="'.strtolower($rb).'"></a>')) {
                $problems[] = "المهمة «{$id}» تحيل إلى {$rb} ولا مرساة له في docs/RUNBOOK.md";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_the_runbook_points_to_the_unified_list_instead_of_copying_it(): void
    {
        $doc = $this->runbook();
        $section = preg_match('/^## الأوامر المجدولة\n(.*?)(?=^## |\z)/msu', $doc, $m) ? $m[1] : '';

        $this->assertNotSame('', $section, 'قسم «## الأوامر المجدولة» غائب عن docs/RUNBOOK.md');
        $this->assertStringContainsString('(ops/load-budget.md)', $section);
        $this->assertStringContainsString('`schedules`', $section);
        $this->assertDoesNotMatchRegularExpression('/^\| `[a-z]+:[a-z0-9:_-]+` \|/m', $section, 'قائمة المهام في load-budget.md وحدها، لا نسخة ثانية هنا');
    }

    public function test_an_unnamed_closure_would_be_reported(): void
    {
        $schedule = new Schedule;
        $event = $schedule->call(fn () => 1)->everyMinute();

        $this->assertSame('', self::identifier($event));
        $this->assertSame('RB-11', RunbookMap::forSchedule('ops:queue-beat:wa-replies'), 'البادئة تغطي كل طابور');
        $this->assertNull(RunbookMap::forSchedule('whatsapp:foo'));
    }
}
