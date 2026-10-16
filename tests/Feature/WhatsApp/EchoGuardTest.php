<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\EchoHandler;
use App\Services\WhatsApp\ReplyRun;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * الحارس المبكر للتعايش (E39، العلم coexistence_guard والمعالجة الكاملة مطفأة): أي صدى يوقف البوت في محادثته ساعة
 * على الأقل بلا تخزين محتوى، وإشعار واحد للمدراء في اليوم مهما تكرر. لا محادثة جديدة لعميل لا نعرفه.
 */
class EchoGuardTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel(['pause_minutes' => 30]);
        $this->admin = User::factory()->admin()->create();
        config(['features.env.coexistence_guard' => true]);
        $this->assertTrue(EchoHandler::guardOn());
        $this->assertFalse(EchoHandler::fullOn());
    }

    private function echo(int $n): string
    {
        $payload = json_decode((string) file_get_contents($this->fixturePath('smb_message_echoes.json')), true);
        $payload['entry'][0]['changes'][0]['value']['message_echoes'][0]['id'] = 'wamid.FIXTURE.echo.g'.$n;

        return (string) json_encode($payload);
    }

    public function test_an_echo_pauses_the_bot_without_storing_anything_and_alerts_once_however_often_it_repeats(): void
    {
        $conv = WhatsAppInbox::conversationFor('16505551234');
        $conv->forceFill(['needs_attention' => true, 'attention_reason' => 'handoff'])->save();
        WaMessage::create(['conversation_id' => $conv->id, 'direction' => 'in', 'source' => 'customer', 'msg_type' => 'text', 'body' => 'سؤال', 'wamid' => 'wamid.TEST.g']);
        $run = new ReplyRun('token', (int) WaMessage::max('id'));

        for ($i = 1; $i <= 10; $i++) {
            $this->postRawWebhook($this->echo($i))->assertOk();
        }

        $this->assertSame(1, WaMessage::count(), 'لا يُخزَّن محتوى الصدى');
        $conv->refresh();
        $this->assertGreaterThanOrEqual(59, now()->diffInMinutes($conv->bot_paused_until), 'ساعة على الأقل ولو كان إيقاف الموظف أقصر');
        $this->assertFalse($conv->needs_attention);
        $this->assertNull($conv->attention_reason);
        $this->assertSame('stale_paused', $run->mayDeliver($conv), 'الرد قيد التوليد يسقط بالإيقاف');
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('title', 'like', '📱 وصلت رسائل من تطبيق واتساب أعمال%')->count());
        $this->assertSame(0, Notification::where('user_id', $this->admin->id)->where('title', 'like', '%تعايش على رقم الإنتاج%')->count(), 'لا تنبيه E20 فوقه');
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
        Http::assertNothingSent();
    }

    public function test_an_echo_for_an_unknown_customer_creates_nothing(): void
    {
        $this->postRawWebhook($this->echo(1))->assertOk();

        $this->assertSame(0, WaConversation::count());
        $this->assertSame(0, WaMessage::count());
    }

    public function test_a_longer_existing_pause_is_never_shortened(): void
    {
        $conv = WhatsAppInbox::conversationFor('16505551234');
        $conv->forceFill(['bot_paused_until' => now()->addHours(5)])->save();

        $this->postRawWebhook($this->echo(1))->assertOk();

        $this->assertGreaterThan(290, now()->diffInMinutes($conv->fresh()->bot_paused_until));
    }

    public function test_full_processing_takes_over_when_both_flags_are_on(): void
    {
        config(['features.env.coexistence_echoes' => true]);
        WhatsAppInbox::conversationFor('16505551234');

        $this->postRawWebhook($this->echo(1))->assertOk();

        $this->assertSame('staff_phone', WaMessage::sole()->source);
        $this->assertSame(0, Notification::where('title', 'like', '📱 وصلت رسائل%')->count());
    }
}
