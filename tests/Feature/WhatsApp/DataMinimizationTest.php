<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendPushNotification;
use App\Models\Notification;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\NotificationService;
use App\Services\WhatsApp\BotTools;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * تقليل البيانات في كل مخرج (E15.10): إشعار الجهاز (طرف ثالث، شاشة القفل) بعنوان ثابت وجسم عام، والجرس بلا رقم هاتف
 * ولا تفاصيل طلب، وأحداث البث بمعرّفات فقط، وJSON الصندوق بلا payload.
 */
class DataMinimizationTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const PHONE = '962790001111';

    private const NAME = 'سامي الأردني';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
        $this->fakeGraph();
        $this->admin = User::factory()->admin()->create();
    }

    private function customer(array $attrs = []): WaConversation
    {
        return WaConversation::factory()->windowOpen()->create($attrs + ['phone' => self::PHONE, 'wa_phone' => self::PHONE, 'display_name' => self::NAME]);
    }

    /** كل ما دخل حمولة وظائف إشعار الجهاز (TestCase يزيّف Bus لها) */
    private function pushes(): array
    {
        return Bus::dispatched(SendPushNotification::class)->map(fn ($j) => ['title' => $j->title, 'body' => $j->body, 'url' => $j->url])->values()->all();
    }

    private function assertNoPersonalData(string $text, string $where): void
    {
        $this->assertDoesNotMatchRegularExpression('/\d{7,}/', $text, "{$where}: رقم من 7 خانات فأكثر");
        $this->assertStringNotContainsString(self::NAME, $text, "{$where}: اسم العميل");
    }

    public function test_a_handoff_push_has_no_name_no_number_and_a_generic_body(): void
    {
        $conv = $this->customer();

        BotTools::handoffFromCode($conv, 'طلب موظفاً');

        $pushes = $this->pushes();
        $this->assertNotEmpty($pushes);
        foreach ($pushes as $p) {
            $this->assertNoPersonalData($p['title'].' '.$p['body'], 'إشعار الجهاز');
            $this->assertSame(NotificationService::WHATSAPP_PUSH_BODY, $p['body']);
            $this->assertSame('/whatsapp/inbox?conv='.$conv->id, $p['url'], 'يفتح المحادثة مباشرة');
        }
        $bell = Notification::where('user_id', $this->admin->id)->sole();
        $this->assertStringContainsString(self::NAME, $bell->body, 'الجرس يحمل الاسم');
        $this->assertStringNotContainsString(self::PHONE, $bell->body);
    }

    public function test_a_quote_request_bell_has_the_request_number_without_details(): void
    {
        $conv = $this->customer();
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'بدي عمرة']);

        BotTools::execute('request_quote', ['type' => 'umrah', 'details' => 'عمرة لشخصين، جواز N1234567 وهاتف 0791234567'], $conv);

        $qr = QuoteRequest::sole();
        $bell = Notification::where('user_id', $this->admin->id)->sole();
        $this->assertStringContainsString((string) $qr->request_number, $bell->body);
        $this->assertStringNotContainsString('N1234567', $bell->body);
        $this->assertStringNotContainsString('0791234567', $bell->body);
        $this->assertStringContainsString('search='.$qr->request_number, $bell->action_url);
        foreach ($this->pushes() as $p) {
            $this->assertNoPersonalData($p['title'].' '.$p['body'], 'إشعار الجهاز');
        }
    }

    public function test_window_reminders_and_assignment_keep_the_number_out(): void
    {
        $conv = $this->customer(['needs_attention' => true, 'last_inbound_at' => now()->subHours(22)]);
        $this->artisan('whatsapp:window-reminders')->assertSuccessful();

        $agent = User::factory()->withPermissions(['whatsapp.view'])->create();
        $this->actingAs($this->admin)->postJson("/api/whatsapp/conversations/{$conv->id}/assign", ['user_id' => $agent->id])->assertOk();

        $bells = Notification::whereIn('user_id', [$this->admin->id, $agent->id])->get();
        $this->assertCount(2, $bells);
        foreach ($bells as $b) {
            $this->assertStringNotContainsString(self::PHONE, $b->body);
            $this->assertSame('/whatsapp/inbox?conv='.$conv->id, $b->action_url);
        }
        $pushes = $this->pushes();
        $this->assertCount(2, $pushes);
        foreach ($pushes as $p) {
            $this->assertNoPersonalData($p['title'].' '.$p['body'], 'إشعار الجهاز');
        }
    }

    public function test_other_notifications_keep_their_text_and_whatsapp_bodies_cannot_be_overridden(): void
    {
        $this->assertSame(['📤 سند صرف', 'تفاصيل'], NotificationService::pushText('📤 سند صرف', 'تفاصيل', ['type' => 'disbursement']));
        $this->assertSame(['عنوان آخر', 'جسم آخر'], NotificationService::pushText('t', 'b', ['type' => 'info', 'push' => ['title' => 'عنوان آخر', 'body' => 'جسم آخر']]));
        $this->assertSame(['💰 طلب تسعير جديد', NotificationService::WHATSAPP_PUSH_BODY],
            NotificationService::pushText('t', self::NAME, ['type' => 'whatsapp', 'push' => ['title' => '💰 طلب تسعير جديد', 'body' => self::NAME]]));
    }

    public function test_the_notice_label_never_falls_back_to_the_phone(): void
    {
        $bare = $this->customer(['display_name' => null, 'wa_profile_name' => null, 'wa_username' => null]);

        $this->assertSame('+'.self::PHONE, $bare->label(), 'label() للصندوق يبقى كما هو');
        $this->assertSame('محادثة #'.$bare->id, $bare->noticeLabel());
    }

    /** أي حدث بث عن محادثة يحمل معرّفها ونوعه فقط؛ وحدث الجرس لصاحبه وحده */
    public function test_broadcast_events_carry_identifiers_only(): void
    {
        foreach (glob(app_path('Events/*.php')) as $file) {
            $class = 'App\\Events\\'.basename($file, '.php');
            if (!is_subclass_of($class, ShouldBroadcast::class)) {
                continue;
            }
            $props = array_map(fn ($p) => $p->getName(), (new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC));
            $props = array_values(array_diff($props, ['socket', 'connection', 'queue', 'broadcastQueue', 'afterCommit']));
            if (preg_match('/Wa|WhatsApp|Conversation/', $class)) {
                $this->assertEqualsCanonicalizing(['conversation_id', 'kind'], $props, $class);
            }
        }
        $this->assertTrue(true);
    }

    public function test_the_inbox_thread_json_has_no_payload(): void
    {
        $conv = $this->customer();
        WaMessage::factory()->create(['conversation_id' => $conv->id, 'direction' => 'out', 'source' => 'bot', 'body' => 'أهلاً',
            'payload' => ['_policy' => ['mode' => 'log', 'reasons' => ['card']], 'to' => self::PHONE]]);

        $json = $this->actingAs($this->admin)->getJson("/api/whatsapp/conversations/{$conv->id}/messages")->assertOk()->json();

        $text = json_encode($json, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('_policy', $text);
        $this->assertStringNotContainsString('payload', $text);
    }
}
