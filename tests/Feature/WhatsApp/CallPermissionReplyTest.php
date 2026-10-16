<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\ReplyState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * رد العميل على طلب إذن الاتصال (E39، العلم calls_webhook): نص عربي مقروء ونوع call_permission لا يوقظ البوت،
 * والموافقة «تدخّل» بسببها. وبدون العلم يبقى interactive كما كان.
 */
class CallPermissionReplyTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        User::factory()->admin()->create();
        config(['features.env.calls_webhook' => true]);
    }

    private function reply(string $response): string
    {
        $payload = json_decode((string) file_get_contents($this->fixturePath('call_permission_reply_accept.json')), true);
        $msg = &$payload['entry'][0]['changes'][0]['value']['messages'][0];
        $msg['timestamp'] = (string) (now()->getTimestamp() - 5);
        $msg['interactive']['call_permission_reply']['response'] = $response;
        $msg['id'] = 'wamid.FIXTURE.callperm.'.$response;

        return (string) json_encode($payload);
    }

    public function test_an_acceptance_is_readable_needs_attention_and_never_wakes_the_bot(): void
    {
        $this->postMetaWebhook('call_permission_reply_accept.json')->assertOk();

        $m = WaMessage::sole();
        $this->assertSame(['in', 'customer', 'call_permission'], [$m->direction, $m->source, $m->msg_type]);
        $this->assertStringStartsWith('📞 وافق العميل على أن نتصل به حتى ', $m->body);
        $this->assertMatchesRegularExpression('/حتى \d{4}-\d{2}-\d{2} \d{2}:\d{2}$/u', $m->body);
        $conv = WaConversation::sole();
        $this->assertTrue($conv->needs_attention);
        $this->assertSame('call_permission', $conv->attention_reason);
        $this->assertFalse(ReplyState::hasPending($conv));
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
        Http::assertNothingSent();
    }

    public function test_a_refusal_is_recorded_without_attention_or_bot(): void
    {
        $this->postRawWebhook($this->reply('reject'))->assertOk();

        $m = WaMessage::sole();
        $this->assertSame(['call_permission', '📞 رفض العميل طلب الاتصال'], [$m->msg_type, $m->body]);
        $conv = WaConversation::sole();
        $this->assertFalse($conv->needs_attention);
        $this->assertSame($m->id, (int) $conv->bot_answered_in_id, 'لا وارد آخر ينتظر: العلامة تتقدّم');
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
    }

    public function test_a_refusal_after_an_unanswered_question_leaves_the_question_pending(): void
    {
        $this->postMetaWebhook('text.json')->assertOk();
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);

        $this->postRawWebhook($this->reply('reject'))->assertOk();

        $conv = WaConversation::sole();
        $this->assertTrue(ReplyState::hasPending($conv));
        $this->assertSame('text', ReplyState::newestPending($conv)->msg_type);
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);
    }

    public function test_without_the_flag_the_reply_stays_a_plain_interactive_message(): void
    {
        config(['features.env.calls_webhook' => false]);

        $this->postMetaWebhook('call_permission_reply_accept.json')->assertOk();

        $this->assertSame('interactive', WaMessage::sole()->msg_type);
        $this->assertFalse(WaConversation::sole()->needs_attention);
    }
}
