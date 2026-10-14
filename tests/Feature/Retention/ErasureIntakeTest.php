<?php

namespace Tests\Feature\Retention;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaErasureRequest;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\HandoffGuard;
use App\Support\Retention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * استقبال «حذف بياناتي» (E13.4): طلب مسجّل ببصمة الرقم، و«تدخّل» وإشعار بلا رقم ولا اسم، وإيصال يبنيه الكود
 * بلا نموذج؛ ورسالة ثانية لا تفتح طلباً ثانياً؛ وأمرا البريد والمهلة.
 */
class ErasureIntakeTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const PHONE = '16505551234';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        $this->fakeGraph();
        $this->admin = User::factory()->admin()->create();
    }

    private function send(string $text)
    {
        $payload = json_decode(file_get_contents($this->fixturePath('text.json')), true);
        data_set($payload, 'entry.0.changes.0.value.messages.0.text.body', $text);
        data_set($payload, 'entry.0.changes.0.value.messages.0.id', 'wamid.E'.uniqid());
        data_set($payload, 'entry.0.changes.0.value.messages.0.timestamp', (string) now()->timestamp);

        return $this->postRawWebhook(json_encode($payload));
    }

    public function test_the_phrase_opens_one_request_with_a_hash_and_flags_the_conversation(): void
    {
        $this->send('السلام عليكم، حذف بياناتي لو سمحتم')->assertOk();
        $this->send('حذف بياناتي')->assertOk();

        $request = WaErasureRequest::sole();
        $conv = WaConversation::sole();
        $this->assertSame(['received', 'whatsapp', $conv->id, Retention::subjectHmac(self::PHONE)], [$request->status, $request->channel, $request->conversation_id, $request->subject_hmac]);
        $this->assertSame(30, (int) round($request->received_at->diffInDays($request->due_at)));
        $this->assertSame([true, 'data_erasure'], [$conv->needs_attention, $conv->attention_reason]);
        $this->assertSame(1, AuditLog::where('action', 'wa.erasure_request')->count());

        $note = Notification::where('user_id', $this->admin->id)->sole();
        $this->assertStringNotContainsString(self::PHONE, $note->title.$note->body);
        $this->assertStringNotContainsString(substr(self::PHONE, -9), $note->title.$note->body);
        $this->assertNotEmpty($conv->wa_profile_name);
        $this->assertStringNotContainsString($conv->wa_profile_name, $note->title.$note->body);
    }

    public function test_the_bot_sends_the_code_built_acknowledgement_without_the_model(): void
    {
        $this->send('حذف بياناتي')->assertOk();
        $conv = WaConversation::sole();

        BotEngine::reply($conv);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
        $out = WaMessage::where('direction', 'out')->sole();
        $this->assertSame(HandoffGuard::erasureConfirmation(false), $out->body);
        $this->assertSame('data_erasure', $conv->fresh()->attention_reason);
    }

    public function test_requests_by_email_are_recorded_without_the_number(): void
    {
        $conv = WaConversation::factory()->create(['phone' => self::PHONE, 'wa_phone' => self::PHONE]);

        $this->assertSame(0, Artisan::call('whatsapp:erasure-request', ['--phone' => '+1 650 555 1234', '--channel' => 'email']));
        Artisan::call('whatsapp:erasure-request', ['--phone' => '+16505551234', '--channel' => 'email']);

        $request = WaErasureRequest::sole();
        $this->assertSame(['email', $conv->id], [$request->channel, $request->conversation_id]);
        $this->assertStringNotContainsString(self::PHONE, json_encode($request->getAttributes()));
    }

    public function test_late_requests_warn_once_a_day(): void
    {
        $conv = WaConversation::factory()->create(['phone' => self::PHONE, 'wa_phone' => self::PHONE]);
        WaErasureRequest::create(['status' => 'received', 'channel' => 'whatsapp', 'conversation_id' => $conv->id, 'subject_hmac' => str_repeat('a', 64),
            'received_at' => now()->subDays(22), 'due_at' => now()->addDays(8)]);
        WaErasureRequest::create(['status' => 'received', 'channel' => 'whatsapp', 'subject_hmac' => str_repeat('b', 64), 'received_at' => now()->subDays(3), 'due_at' => now()->addDays(27)]);

        Artisan::call('whatsapp:erasure-sla');
        Artisan::call('whatsapp:erasure-sla');

        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->count());
        $this->assertStringContainsString('بقي 8 يوماً', Notification::sole()->body);
    }

    public function test_an_admin_number_writing_the_phrase_opens_nothing(): void
    {
        WaAdminNumber::factory()->create(['phone' => self::PHONE]);

        $this->send('حذف بياناتي')->assertOk();

        $this->assertSame(0, WaErasureRequest::count());
        Queue::assertPushed(ProcessWhatsAppReply::class);
    }
}
