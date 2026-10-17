<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * البوابة (E22): الإيقاف اليدوي SENDING_SUSPENDED دائماً، وBLOCKED يمنع في الوضعين (حتى والعلم مطفأ)، وLIMITED لا يمنع
 * شيئاً، وTOKEN_INVALID بعلمه وحده. والبوت لا يستدعي النموذج والقناة محجوبة، ويرفع «تدخّلاً» بسبب ظاهر.
 */
class ChannelHealthGateTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.channel_health' => true]);
        $this->configureChannel();
    }

    private function health(string $state): void
    {
        WaBotSetting::current()->forceFill(['wa_health' => $state])->save();
    }

    private function send(?WaConversation $conv = null): array
    {
        return WhatsAppChannel::sendText($conv ?? WaConversation::factory()->windowOpen()->create(), 'مرحباً', 'system', sendKey: SendKey::optout('wamid.GATE.'.uniqid()));
    }

    public function test_the_manual_suspension_is_always_sending_suspended_even_when_blocked(): void
    {
        Http::fake();
        $this->health('BLOCKED');
        WaBotSetting::current()->forceFill(['wa_suspended' => true, 'wa_suspended_source' => 'manual'])->save();

        $this->assertSame(WhatsAppChannel::ERR_SUSPENDED, $this->send()['code']);
        Http::assertNothingSent();
    }

    public function test_limited_blocks_nothing_and_unknown_or_available_pass(): void
    {
        foreach (['LIMITED', 'UNKNOWN', 'AVAILABLE'] as $state) {
            $this->health($state);
            $this->fakeGraph();
            $this->assertTrue($this->send()['ok'], $state);
        }
    }

    public function test_blocked_refuses_with_its_own_code_and_no_http_call_even_with_the_flag_off(): void
    {
        config(['features.env.channel_health' => false]);
        Http::fake();
        $this->health('BLOCKED');
        WaBotSetting::current()->forceFill(['wa_health_until' => now()->addDays(2)])->save();

        $res = $this->send();

        $this->assertFalse($res['ok']);
        $this->assertSame(WhatsAppChannel::ERR_CHANNEL_BLOCKED, $res['code']);
        $this->assertStringContainsString('ميتا تحجب الإرسال من رقم الشركة حتى', $res['error']);
        Http::assertNothingSent();
    }

    public function test_the_bot_does_not_call_the_model_when_the_channel_is_blocked_and_flags_the_conversation(): void
    {
        $this->health('BLOCKED');
        Http::fake();
        $conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'مرحبا']);

        $result = BotEngine::reply($conv);

        $this->assertFalse($result['sent']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage'));
        Http::assertNothingSent();
        $conv->refresh();
        $this->assertTrue((bool) $conv->needs_attention);
        $this->assertSame('channel_blocked', $conv->attention_reason);
    }

    public function test_token_invalid_stops_the_bot_only_with_its_flag(): void
    {
        $this->health('TOKEN_INVALID');
        $conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'مرحبا']);
        config(['features.env.channel_health_block_on_token' => true]);
        Http::fake();

        $this->assertFalse(BotEngine::reply($conv)['sent']);
        Http::assertNothingSent();
        $this->assertSame('channel_blocked', $conv->fresh()->attention_reason);
    }

    public function test_the_admin_assistant_is_not_run_on_a_blocked_channel(): void
    {
        $this->health('BLOCKED');
        Http::fake();
        $conv = WaConversation::factory()->windowOpen()->create();
        WaAdminNumber::factory()->create(['phone' => $conv->phone]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'ملخص اليوم']);

        $this->assertSame('channel_blocked', BotEngine::reply($conv)['reason']);
        Http::assertNothingSent();
    }

    public function test_a_staff_reply_is_refused_with_a_clear_arabic_message(): void
    {
        $this->health('BLOCKED');
        Http::fake();
        $staff = User::factory()->admin()->create();
        $conv = WaConversation::factory()->windowOpen()->create();

        $this->actingAs($staff)->postJson("/api/whatsapp/conversations/{$conv->id}/send", ['body' => 'مرحباً'])
            ->assertStatus(422)->assertJsonPath('code', WhatsAppChannel::ERR_CHANNEL_BLOCKED);
        Http::assertNothingSent();
    }
}
