<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * جدول أخطاء ميتا: الرمز وfbtrace_id يُحفظان مع الرسالة، والنص عربي، والفعل حسب الجدول:
 * 368 يوقف الإرسال (قابل للإطفاء)، و131031 و131048 تنبيه فقط، و190 تنبيه ويُسجَّل وقته.
 */
class MetaErrorCatalogTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->admin = User::factory()->admin()->create();
    }

    private function sendFailingWith(int $code): array
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'x', 'code' => $code, 'fbtrace_id' => 'TRACE123']], 400)]);

        return WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'أهلاً');
    }

    private function alerts(): int
    {
        return Notification::where('user_id', $this->admin->id)->count();
    }

    public function test_the_failed_message_keeps_the_meta_code_and_trace(): void
    {
        $this->sendFailingWith(131026);

        $message = WaMessage::sole();
        $this->assertSame(131026, $message->meta_code);
        $this->assertSame('TRACE123', $message->fbtrace_id);
        $this->assertStringStartsWith('[131026] تعذّر التسليم', $message->error_message);
    }

    public function test_368_suspends_sending_and_alerts(): void
    {
        $this->sendFailingWith(368);

        $this->assertTrue(WaBotSetting::current()->wa_suspended);
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('title', '⛔ إيقاف إرسال الواتساب')->count());
    }

    public function test_autosuspend_can_be_switched_off(): void
    {
        config(['services.whatsapp.health_autosuspend' => false]);

        $this->sendFailingWith(368);

        $this->assertFalse(WaBotSetting::current()->wa_suspended);
        $this->assertSame(1, $this->alerts(), 'يبقى التنبيه');
    }

    public function test_131048_only_alerts(): void
    {
        $this->sendFailingWith(131048);

        $this->assertFalse(WaBotSetting::current()->wa_suspended);
        $this->assertSame(1, $this->alerts());
    }

    public function test_190_alerts_and_records_when_it_happened(): void
    {
        $result = $this->sendFailingWith(190);

        $this->assertSame('TOKEN_INVALID', $result['code']);
        $this->assertNotNull(Cache::get('wa:last_auth_error_at'));
        $this->assertSame(1, $this->alerts());
    }

    public function test_status_webhook_failure_uses_the_same_catalog(): void
    {
        Queue::fake();
        $conv = WaConversation::factory()->windowOpen()->create();
        $out = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'wamid' => 'wamid.STATUS.1']);
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '102290129340398', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => self::TEST_PHONE_NUMBER_ID],
            'statuses' => [['id' => 'wamid.STATUS.1', 'status' => 'failed', 'timestamp' => '1760425200', 'recipient_id' => '16505551234',
                'errors' => [['code' => 368, 'title' => 'Temporarily blocked for policies violations']]]],
        ]]]]]];

        $this->postRawWebhook(json_encode($payload))->assertOk();

        $out->refresh();
        $this->assertSame(368, $out->meta_code);
        $this->assertStringStartsWith('[368] حساب واتساب مقيّد', $out->error_message);
        $this->assertTrue(WaBotSetting::current()->wa_suspended);
    }
}
