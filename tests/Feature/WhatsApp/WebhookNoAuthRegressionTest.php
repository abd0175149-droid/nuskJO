<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * نطاق المحادثات محلي (visibleTo) لا عام: الويبهوك وطابور الردود والمساعد الإداري يعملون بلا مستخدم
 * ويجب أن يجدوا المحادثة نفسها، وإلا تكرّرت المحادثات واختفت الردود.
 */
class WebhookNoAuthRegressionTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    public function test_conversation_model_has_no_global_scope(): void
    {
        $this->assertSame([], (new WaConversation)->getGlobalScopes());
    }

    public function test_two_messages_from_one_number_without_a_user_share_one_conversation(): void
    {
        Queue::fake();
        $this->configureChannel();
        $raw = file_get_contents($this->fixturePath('text.json'));

        $this->postRawWebhook($raw)->assertOk();
        $this->postRawWebhook(str_replace('wamid.HBgLMTY1MDU1NTEyMzQVAgASGBQyNjE5Qjc5MTJEMUZBMTVBQzE1RAA=', 'wamid.SECOND', $raw))->assertOk();

        $this->assertGuest();
        $this->assertSame(1, WaConversation::count());
        $conversation = WaConversation::first();
        $this->assertSame(2, $conversation->messages()->count());
        $this->assertNotNull(WaConversation::find($conversation->id));
    }
}
