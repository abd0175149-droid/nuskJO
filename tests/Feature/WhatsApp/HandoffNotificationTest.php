<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Events\WhatsApp\ConversationHandedOff;
use App\Jobs\SendPushNotification;
use App\Listeners\WhatsApp\BusListener;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\NotificationService;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\Tools\ToolInvoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * إشعار التحويل (E21): الحدث ConversationHandedOff من escalate وحدها، والعلم wa_event_bus موضع القرار الوحيد بين
 * الإشعار المباشر والمستمع، فيصل إشعار واحد للمدير وواحد للموكَّل في الحالتين. ودفع الجهاز بنص عام.
 */
class HandoffNotificationTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const QUOTE = ['type' => 'flight', 'route_from' => 'عمّان', 'route_to' => 'جدة', 'depart_date' => '2026-12-20', 'adults' => 2,
        'details' => 'تفصيل خاص بالعميل لا يخرج للجهاز'];

    private User $admin;

    private User $assignee;

    private WaConversation $conv;

    /** @var list<ConversationHandedOff> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.extended' => true]);
        $this->configureChannel();
        $this->admin = User::factory()->admin()->create();
        $this->assignee = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply'], 'desk')->create();
        $this->conv = WaConversation::factory()->windowOpen()->create(['display_name' => 'سارة التجريبية', 'assigned_to' => $this->assignee->id]);
        Event::listen(ConversationHandedOff::class, function (ConversationHandedOff $e) {
            $this->events[] = $e;
        });
    }

    private function quote(): array
    {
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'بدي تسعيرة']);

        return ToolInvoker::run('request_quote', self::QUOTE, $this->conv->fresh(), ['request_quote'])['output'];
    }

    private function notes(User $user): int
    {
        return Notification::where('user_id', $user->id)->count();
    }

    public function test_with_the_bus_a_quote_hands_off_once_and_each_recipient_gets_one_notice_with_a_generic_push(): void
    {
        config(['features.env.wa_event_bus' => true]);

        $out = $this->quote();

        $this->assertCount(1, $this->events);
        $qr = QuoteRequest::sole();
        $this->assertSame([$this->conv->id, $this->assignee->id, $qr->id, 'quote'],
            [$this->events[0]->conversationId, $this->events[0]->assigneeId, $this->events[0]->quoteRequestId, $this->events[0]->reason]);
        $this->assertSame($out['request_number'], $qr->request_number);
        $this->assertSame([1, 1], [$this->notes($this->admin), $this->notes($this->assignee)]);

        // الجرس داخل النظام بالاسم ورقم الطلب، والجهاز بنص عام
        $bell = Notification::where('user_id', $this->assignee->id)->sole();
        $this->assertSame('💰 طلب تسعير جديد', $bell->title);
        $this->assertStringContainsString((string) $qr->request_number, $bell->body);
        Bus::assertDispatched(SendPushNotification::class, 2);
        Bus::assertDispatched(SendPushNotification::class, function (SendPushNotification $job) use ($qr) {
            $all = $job->title.' '.$job->body;

            return $job->body === NotificationService::WHATSAPP_PUSH_BODY && !str_contains($all, 'سارة')
                && !str_contains($all, (string) $qr->request_number) && !str_contains($all, 'تفصيل خاص');
        });

        // التدقيق من المستمع بالسبب، بفاعل البوت
        $audit = AuditLog::where('action', AuditAction::WaHandoff->value)->sole();
        $this->assertSame(['bot', 'quote', $qr->id, $this->assignee->id], [$audit->actor_type, $audit->changes['reason'], $audit->changes['quote_request_id'], $audit->changes['assigned_to']]);
    }

    public function test_without_the_bus_the_direct_path_notifies_exactly_as_before_and_the_listener_stays_silent(): void
    {
        $this->quote();

        $this->assertCount(1, $this->events, 'الحدث يُطلق دائماً');
        $this->assertSame([1, 1], [$this->notes($this->admin), $this->notes($this->assignee)]);
        $audit = AuditLog::where('action', AuditAction::WaHandoff->value)->sole();
        $this->assertSame('💰 طلب تسعير جديد', $audit->changes['title']);
        $this->assertArrayNotHasKey('reason', $audit->changes, 'صف escalate المباشر');
    }

    public function test_a_second_handoff_in_the_same_minute_does_not_notify_again(): void
    {
        config(['features.env.wa_event_bus' => true]);

        BotTools::handoffFromCode($this->conv->fresh(), 'أول');
        BotTools::handoffFromCode($this->conv->fresh(), 'ثانٍ');

        $this->assertCount(2, $this->events);
        $this->assertSame([1, 1], [$this->notes($this->admin), $this->notes($this->assignee)]);
    }

    public function test_an_explicit_request_for_a_human_hands_off_once(): void
    {
        config(['features.env.wa_event_bus' => true]);
        $this->fakeGraph();
        $this->fakeGemini('لا يُطلب');
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'بدي احكي مع موظف']);

        BotEngine::reply($this->conv->fresh());

        $this->assertCount(1, $this->events);
        $this->assertSame('human_request', $this->events[0]->reason);
        $this->assertFalse($this->conv->fresh()->bot_enabled);
    }

    public function test_a_reply_claiming_a_handoff_without_the_tool_is_a_guard_claim(): void
    {
        $this->fakeGraph();
        $this->fakeGemini('تم تحويل محادثتك لأحد زملائنا وسيتواصل معك قريباً.');
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'عندي مشكلة بالحجز']);

        BotEngine::reply($this->conv->fresh());

        $this->assertSame(['guard_claim'], array_map(fn ($e) => $e->reason, $this->events));
    }

    public function test_the_fail_safe_is_not_a_handoff(): void
    {
        config(['features.env.wa_event_bus' => true]);
        $this->fakeGraph(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'bad key']], 403)]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'مرحبا']);

        $this->assertSame('fail-safe', BotEngine::reply($this->conv->fresh())['reason']);

        $this->assertSame([], $this->events);
    }

    public function test_a_listener_failure_is_swallowed_and_logged_with_ids_only(): void
    {
        $this->captureLogs();
        $ran = 0;
        $listener = new class($ran) extends BusListener
        {
            public function __construct(private int &$ran) {}

            public function handle(ConversationHandedOff $event): void
            {
                $this->guarded($event, function () {
                    $this->ran++;
                    throw new \RuntimeException('سارة التجريبية 16505551234');
                });
            }
        };

        $listener->handle(new ConversationHandedOff(5, null, null, 'quote'));
        $this->assertSame(0, $ran, 'العلم مطفأ: لا عمل');

        config(['features.env.wa_event_bus' => true]);
        $listener->handle(new ConversationHandedOff(5, null, null, 'quote'));

        $this->assertSame(1, $ran);
        $line = collect($this->logged)->firstWhere('message', 'wa.listener_failed');
        $this->assertSame(['ConversationHandedOff', 'RuntimeException', 5], [$line['context']['event'], $line['context']['type'], $line['context']['conv_id']]);
        $this->assertStringNotContainsString('16505551234', json_encode($this->logged));
    }
}
