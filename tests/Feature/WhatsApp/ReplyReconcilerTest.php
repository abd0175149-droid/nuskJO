<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\ReplyReconciler;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * المُصالِح: وارد بقي بلا رد دقيقتين ولا وظيفة عليه يُعاد جدولته مرتين كحد ثم يصير «تدخّل»،
 * ويحترم البوابات (متأخر، حد الساعة، مع موظف، رقم إدارة). وإنذار تأخر الطابور مرة في الساعة.
 */
#[Group('INV-15')]
class ReplyReconcilerTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel(['reliability' => ['reconcile' => true]]);
    }

    /** محادثة فيها وارد لم يُرد عليه، وصل قبل $minutesAgo دقائق */
    private function lostReply(int $minutesAgo = 3, array $attributes = []): WaConversation
    {
        $conv = WaConversation::factory()->create($attributes + ['last_inbound_at' => now(), 'bot_enabled' => true]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'هل العرض متاح؟']);
        $this->travel($minutesAgo)->minutes();

        return $conv;
    }

    private function scheduledFor(WaConversation $conv): int
    {
        return Queue::pushed(ProcessWhatsAppReply::class, fn ($job) => $job->conversationId === $conv->id)->count();
    }

    public function test_a_lost_reply_is_rescheduled(): void
    {
        $this->captureLogs();
        $conv = $this->lostReply();
        $messageId = WaMessage::sole()->id;

        $this->artisan('whatsapp:reconcile-replies')->assertSuccessful();

        $this->assertSame(1, $this->scheduledFor($conv));
        Queue::assertPushed(ProcessWhatsAppReply::class, fn ($job) => Cache::get(ProcessWhatsAppReply::debounceKey($conv->id)) === $job->token);
        $this->assertFalse($conv->fresh()->needs_attention);
        $this->assertLogged('info', 'reply.reconcile', ['conv_id' => $conv->id, 'message_id' => $messageId, 'count' => 1]);
    }

    public function test_nothing_runs_while_the_flag_is_off(): void
    {
        $this->configureChannel(['reliability' => ['reconcile' => false]]);
        $this->lostReply();

        $this->assertSame('flag_off', ReplyReconciler::run()['skipped']);
        Queue::assertNothingPushed();
    }

    public static function notMine(): array
    {
        return [
            'bot off system-wide' => [['enabled' => false], 'bot_off'],
            'emergency stop' => [['wa_suspended' => true], 'suspended'],
        ];
    }

    #[DataProvider('notMine')]
    public function test_it_stays_out_when_the_bot_cannot_reply(array $settings, string $skipped): void
    {
        $this->configureChannel($settings);
        $this->lostReply();

        $this->assertSame($skipped, ReplyReconciler::run()['skipped']);
        Queue::assertNothingPushed();
    }

    public static function leftAlone(): array
    {
        return [
            'arrived under two minutes ago' => [fn () => null, 1],
            'already flagged for staff' => [fn (WaConversation $c) => $c->update(['needs_attention' => true])],
            'bot switched off for the conversation' => [fn (WaConversation $c) => $c->update(['bot_enabled' => false])],
            'bot paused' => [fn (WaConversation $c) => $c->update(['bot_paused_until' => now()->addMinutes(10)])],
            'job running' => [fn (WaConversation $c) => Cache::put(ProcessWhatsAppReply::runningKey($c->id), time(), 130)],
            'already answered' => [fn (WaConversation $c) => $c->update(['bot_answered_in_id' => WaMessage::max('id')])],
            'admin number' => [fn (WaConversation $c) => WaAdminNumber::factory()->create(['phone' => $c->phone])],
            'inbound older than the lookback' => [fn () => null, 30],
        ];
    }

    #[DataProvider('leftAlone')]
    public function test_conversations_it_must_not_touch(\Closure $arrange, int $minutesAgo = 3): void
    {
        $conv = $this->lostReply($minutesAgo);
        $arrange($conv);
        $before = $conv->fresh()->only(['needs_attention', 'attention_reason', 'bot_answered_in_id']);

        $this->assertSame([], ReplyReconciler::run()['actions']);
        Queue::assertNothingPushed();
        $this->assertSame($before, $conv->fresh()->only(['needs_attention', 'attention_reason', 'bot_answered_in_id']));
    }

    public function test_a_reaction_alone_is_not_a_lost_reply(): void
    {
        $conv = WaConversation::factory()->create(['last_inbound_at' => now()]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'msg_type' => 'reaction', 'body' => '👍']);
        $this->travel(3)->minutes();

        $this->assertSame([], ReplyReconciler::run()['actions']);
    }

    public function test_a_stale_inbound_goes_to_staff(): void
    {
        $conv = $this->lostReply(12);   // أقصى عمر للرد 10 دقائق

        ReplyReconciler::run();

        Queue::assertNothingPushed();
        $this->assertSame('stale', $conv->fresh()->attention_reason);
        $this->assertTrue($conv->fresh()->needs_attention);
    }

    public function test_the_hourly_bot_limit_is_respected(): void
    {
        $conv = $this->lostReply();
        Cache::put('wa:rate:'.$conv->id.':'.now()->format('YmdH'), WaBotSetting::current()->rate_limit_per_hour);

        ReplyReconciler::run();

        Queue::assertNothingPushed();
        $this->assertSame('rate_limit', $conv->fresh()->attention_reason);
    }

    public function test_after_two_attempts_the_conversation_goes_to_staff(): void
    {
        $conv = $this->lostReply();

        foreach (['scheduled', 'scheduled', 'reconcile_exhausted'] as $expected) {
            $this->assertSame($expected, ReplyReconciler::run()['actions'][0]['action']);
            $this->travel(1)->minutes();
        }

        $this->assertSame(2, $this->scheduledFor($conv));
        $this->assertSame('reconcile_exhausted', $conv->fresh()->attention_reason);
        $this->assertSame([], ReplyReconciler::run()['actions']);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $stale = $this->lostReply(9);
        $conv = $this->lostReply();      // الأولى صار عمرها 12 دقيقة

        $this->artisan('whatsapp:reconcile-replies --dry-run')
            ->expectsOutputToContain('تجربة')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertFalse($stale->fresh()->needs_attention);
        $this->assertNull(Cache::get('wa:recon:'.$conv->id.':'.$conv->messages()->value('id')));
        $this->assertSame(['stale', 'scheduled'], array_column(ReplyReconciler::run(true)['actions'], 'action'));
    }

    public function test_a_rescheduled_reply_is_sent_once(): void
    {
        $conv = $this->lostReply();
        $this->fakeGraph();
        $this->fakeGemini('نعم، العرض متاح حتى نهاية الشهر.');

        ReplyReconciler::run();
        Queue::pushed(ProcessWhatsAppReply::class)->first()->handle();

        $this->assertSame(1, WaMessage::where('direction', 'out')->where('source', 'bot')->count());
        $this->assertSame([], ReplyReconciler::run()['actions']);
    }

    // ==================== تأخر الطابور ====================

    private function queuedJob(int $minutesWaiting, ?int $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => $reservedAt,
            'available_at' => now()->subMinutes($minutesWaiting)->getTimestamp(), 'created_at' => now()->subMinutes($minutesWaiting)->getTimestamp(),
        ]);
    }

    public function test_a_lagging_queue_alerts_admins_once_an_hour_even_with_the_flag_off(): void
    {
        $this->configureChannel(['reliability' => ['reconcile' => false]]);
        $admin = User::factory()->admin()->create();
        $this->queuedJob(6);

        $this->assertSame(1, ReplyReconciler::run()['queue_lag']);
        ReplyReconciler::run();

        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', '🐢 تأخر طابور ردود واتساب')->count());
    }

    public function test_recent_or_reserved_jobs_are_not_lag(): void
    {
        User::factory()->admin()->create();
        $this->queuedJob(3);
        $this->queuedJob(20, now()->getTimestamp());

        $this->assertSame(0, ReplyReconciler::run()['queue_lag']);
        $this->assertSame(0, Notification::count());
    }

    public function test_it_runs_every_minute_with_a_short_overlap_lock(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains((string) $e->command, 'whatsapp:reconcile-replies'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(5, $event->expiresAt);
    }
}
