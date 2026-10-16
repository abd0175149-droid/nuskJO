<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Jobs\ProcessWhatsAppReply;
use App\Jobs\SendPushNotification;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaWebhookEvent;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\WebhookIntake;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * الإعادة المحروسة من صفحة الإخفاقات (E24): الفشل العابر داخل النافذة وحده يُعاد على الصف نفسه بالمفتاح المحرَّر
 * نفسه وbiz_opaque نفسه، مع تدقيق. لا إعادة لغير المؤكد ولا لرموز النافذة أو الحساب، ولا خارج النطاق، والنقرة
 * المزدوجة 409. queue:retry لأصناف آمنة وحدها، وإعادة الويبهوك المتوقف بتأكيد ثانٍ ومنسوبة إلى المدير.
 */
class RedriveTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private User $admin;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.failures_center' => true, 'features.env.failures_redrive' => true, 'features.env.send_ledger' => true]);
        $this->configureChannel();
        $this->admin = User::factory()->admin()->create();
        $this->conv = WaConversation::factory()->windowOpen()->create();
    }

    private function graphError(int $code, int $http = 400): array
    {
        return ['error' => ['message' => 'err', 'type' => 'OAuthException', 'code' => $code, 'error_data' => ['details' => 'x'], 'fbtrace_id' => 'AxE24Test']];
    }

    /** رد بوت يفشل عند ميتا بالرمز المعطى (الدفتر يحرّر مفتاحه)، ثم ميتا تقبل ما بعده */
    private function failedBotReply(int $code = 131000, int $failuresAfter = 0): WaMessage
    {
        $sequence = Http::sequence()->push($this->graphError($code), 400);
        for ($i = 0; $i < $failuresAfter; $i++) {
            $sequence->push($this->graphError($code), 400);
        }
        Http::fake(['graph.facebook.com/*' => $sequence->push(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.REDRIVE.OK']]])]);
        $r = WhatsAppChannel::sendText($this->conv, 'السعر يرسله الموظف قريباً', 'bot', sendKey: SendKey::reply($this->conv, 7, 'text'));
        $this->assertFalse($r['ok']);

        $row = WaMessage::where('direction', 'out')->sole();
        $this->assertSame('failed', $row->status);
        $this->assertNull($row->send_key);
        $this->assertSame(SendKey::reply($this->conv, 7, 'text'), $row->payload['_released_key']);

        return $row;
    }

    private function redrive(WaMessage $m, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson("/api/whatsapp/messages/{$m->id}/redrive");
    }

    public function test_a_transient_failure_is_resent_on_the_same_row_with_the_same_key(): void
    {
        $row = $this->failedBotReply(131000);

        $res = $this->redrive($row)->assertOk()->assertJsonPath('ok', true);

        $row->refresh();
        $this->assertSame('sent', $row->status);
        $this->assertSame('wamid.REDRIVE.OK', $row->wamid);
        $this->assertSame(SendKey::reply($this->conv, 7, 'text'), $row->send_key, 'المفتاح المحرَّر نفسه');
        $this->assertNull($row->error_code);
        $this->assertSame(1, (int) data_get($row->payload, '_redrive.n'));
        $this->assertSame(1, WaMessage::where('direction', 'out')->count(), 'لا صف جديد');
        $this->assertSame('sent', $res->json('message.status'));

        $calls = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/messages'));
        $this->assertCount(2, $calls);
        [$second] = $calls[1];
        $this->assertSame('nj:m:'.$row->id, $second['biz_opaque_callback_data']);
        $this->assertSame('السعر يرسله الموظف قريباً', $second['text']['body']);
        $this->assertSame($this->conv->wa_phone, $second['to']);

        $audit = AuditLog::where('action', AuditAction::WaRedrive->value)->sole();
        $this->assertSame($row->id, (int) $audit->entity_id);
        $this->assertSame(['conversation_id' => $this->conv->id, 'old_code' => 131000, 'result' => 'ok'], $audit->changes);
        $this->assertSame($this->admin->id, (int) $audit->user_id);
    }

    public function test_an_unknown_outcome_is_never_resent(): void
    {
        $row = WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'status' => 'unknown', 'send_key' => 'reply:1:1:text',
            'payload' => ['type' => 'text', 'text' => ['body' => 'x']]]);
        Http::fake();

        $this->redrive($row)->assertStatus(422)->assertJsonPath('code', 'MAYBE_DELIVERED');
        Http::assertNothingSent();
        $this->assertSame(0, AuditLog::where('action', AuditAction::WaRedrive->value)->count());
    }

    public function test_a_window_code_is_refused(): void
    {
        $window = $this->failedBotReply(131047);

        $this->redrive($window)->assertStatus(422)->assertJsonPath('code', 'NOT_TRANSIENT');
        $this->assertSame('failed', $window->fresh()->status);
    }

    public function test_an_account_code_is_refused(): void
    {
        $restricted = $this->failedBotReply(368);
        $this->redrive($restricted)->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_ERROR')
            ->assertJsonPath('error', 'خطأ حساب — يُعالج من WhatsApp Manager لا بالإعادة.');
        $this->assertSame('failed', $restricted->fresh()->status);
    }

    public function test_a_test_send_is_never_redriven(): void
    {
        $row = $this->failedBotReply();
        $row->forceFill(['source' => 'test'])->save();

        $this->redrive($row)->assertStatus(422)->assertJsonPath('code', 'NOT_REDRIVABLE');
        $this->assertSame('failed', $row->fresh()->status);
    }

    public function test_a_closed_window_is_refused_without_any_http(): void
    {
        $row = $this->failedBotReply();
        $this->conv->update(['last_inbound_at' => now()->subDays(2)]);
        $before = count(Http::recorded());

        $this->redrive($row)->assertStatus(422)->assertJsonPath('code', 'WINDOW_CLOSED');

        $this->assertCount($before, Http::recorded());
        $this->assertSame('failed', $row->fresh()->status);
    }

    public function test_a_conversation_outside_the_staff_scope_is_a_404(): void
    {
        $row = $this->failedBotReply();
        $outsider = $this->staffWith('supervisor', ['whatsapp.view', 'whatsapp.settings'], ['visa']);

        $this->redrive($row, $outsider)->assertNotFound();
        $this->assertSame('failed', $row->fresh()->status);
    }

    public function test_a_concurrent_second_click_is_a_409(): void
    {
        $row = $this->failedBotReply();
        $lock = Cache::lock('wa:redrive:'.$row->id, 30);
        $this->assertTrue($lock->get());

        $this->redrive($row)->assertStatus(409)->assertJsonPath('code', 'REDRIVE_BUSY');

        $lock->release();
        $this->assertSame('failed', $row->fresh()->status);
    }

    public function test_a_key_taken_by_another_row_is_a_409(): void
    {
        $row = $this->failedBotReply();
        WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'send_key' => $row->payload['_released_key']]);

        $this->redrive($row)->assertStatus(409)->assertJsonPath('code', 'REDRIVE_KEY_TAKEN');
        $this->assertSame('failed', $row->fresh()->status);
    }

    public function test_a_second_failure_releases_the_key_again_for_a_later_redrive(): void
    {
        $row = $this->failedBotReply(131000, failuresAfter: 1);
        $key = $row->payload['_released_key'];

        $this->redrive($row)->assertStatus(422)->assertJsonPath('ok', false);
        $row->refresh();
        $this->assertSame('failed', $row->status);
        $this->assertNull($row->send_key);
        $this->assertSame($key, $row->payload['_released_key']);
        $this->assertSame('META_TRANSIENT', $row->error_code);
        $this->assertNotSame('ok', AuditLog::where('action', AuditAction::WaRedrive->value)->sole()->changes['result'], 'التدقيق يحمل النتيجة الفاشلة');

        $this->redrive($row)->assertOk();
        $this->assertSame('sent', $row->fresh()->status);
        $this->assertSame(2, (int) data_get($row->fresh()->payload, '_redrive.n'));
        $this->assertSame(1, WaMessage::where('direction', 'out')->count());
    }

    public function test_the_redrive_flag_off_hides_the_action(): void
    {
        $row = $this->failedBotReply();
        config(['features.env.failures_redrive' => false]);

        $this->redrive($row)->assertNotFound();
    }

    public function test_retry_job_refuses_reply_jobs_and_retries_allowlisted_classes(): void
    {
        Queue::fake();
        $reply = (string) Str::uuid();
        $push = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            ['uuid' => $reply, 'connection' => 'database', 'queue' => 'wa-replies', 'payload' => json_encode(['displayName' => ProcessWhatsAppReply::class, 'data' => ['command' => 'x']]),
                'exception' => 'boom', 'failed_at' => now()],
            ['uuid' => $push, 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['uuid' => $push, 'displayName' => SendPushNotification::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => SendPushNotification::class,
                    'command' => serialize(new SendPushNotification($this->admin->id, 't', 'b'))], 'attempts' => 1]),
                'exception' => 'boom', 'failed_at' => now()],
        ]);

        $this->actingAs($this->admin)->postJson("/api/ops/failed-jobs/{$reply}/retry")->assertStatus(422)->assertJsonPath('code', 'REPLY_JOB');
        $this->assertTrue(DB::table('failed_jobs')->where('uuid', $reply)->exists());

        $this->actingAs($this->admin)->postJson("/api/ops/failed-jobs/{$push}/retry")->assertOk();
        $this->assertFalse(DB::table('failed_jobs')->where('uuid', $push)->exists(), 'queue:retry أعادها إلى الطابور');
        $audit = AuditLog::where('action', AuditAction::OpsJobRetry->value)->sole();
        $this->assertSame('SendPushNotification', $audit->entity_label);

        $this->actingAs($this->admin)->postJson('/api/ops/failed-jobs/'.Str::uuid().'/retry')->assertNotFound();
    }

    public function test_a_dead_webhook_needs_a_second_confirmation_and_is_audited_as_the_admin(): void
    {
        config(['features.env.inbound_event_log' => true]);
        Queue::fake();
        $raw = (string) file_get_contents($this->fixturePath('status_delivered.json'));
        [$event] = WebhookIntake::store($raw);
        $event->forceFill(['status' => WaWebhookEvent::DEAD, 'attempts' => 3])->save();

        $this->actingAs($this->admin)->postJson("/api/whatsapp/webhook-events/{$event->id}/replay")->assertStatus(422)->assertJsonPath('code', 'CONFIRM_REQUIRED');

        $this->actingAs($this->admin)->postJson("/api/whatsapp/webhook-events/{$event->id}/replay", ['confirm' => true])->assertOk()
            ->assertJsonPath('status', WaWebhookEvent::PROCESSED);
        $audit = AuditLog::where('action', AuditAction::WaWebhookReplay->value)->sole();
        $this->assertSame('user', $audit->actor_type);
        $this->assertSame($this->admin->id, (int) $audit->user_id);
    }

    public function test_every_action_requires_the_settings_permission(): void
    {
        $row = $this->failedBotReply();
        // يرى المحادثة (فيمر الربط المقيّد) ولا يملك whatsapp.settings
        $staff = $this->staffWith('sales', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);

        $this->redrive($row, $staff)->assertForbidden();
        $this->actingAs($staff)->postJson('/api/ops/failed-jobs/'.Str::uuid().'/retry')->assertForbidden();
        $this->actingAs($staff)->postJson('/api/whatsapp/webhook-events/1/replay')->assertForbidden();
    }

    public function test_redrive_is_throttled_at_ten_a_minute(): void
    {
        $row = WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'status' => 'unknown']);
        for ($i = 0; $i < 10; $i++) {
            $this->redrive($row)->assertStatus(422);
        }
        $this->redrive($row)->assertStatus(429);
    }
}
