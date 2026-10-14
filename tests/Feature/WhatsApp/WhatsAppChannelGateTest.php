<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الأقفال الثلاثة في WhatsAppChannel::dispatch وترتيبها:
 *   1) القناة مهيّأة  2) لا قفل طارئ  3) نافذة 24 ساعة مفتوحة.
 *
 * Graph مزيّف في كل حالة، فلو تجاوز الكود قفلاً لسُجّل الطلب وفشل assertNothingSent.
 */
class WhatsAppChannelGateTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
        $this->fakeGraph();
    }

    private function assertBlocked(array $result, string $code, WaConversation $conv): void
    {
        $this->assertFalse($result['ok']);
        $this->assertSame($code, $result['code']);
        $this->assertNull($result['message_id']);
        $this->assertNotEmpty($result['error']);

        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
        $this->assertNull($conv->fresh()->last_outbound_at);
    }

    public static function unconfiguredChannels(): array
    {
        return [
            'no token' => [['wa_token' => null]],
            'no phone number id' => [['wa_phone_number_id' => null]],
            'nothing configured' => [['wa_token' => null, 'wa_phone_number_id' => null]],
        ];
    }

    #[Group('INV-05')]
    #[DataProvider('unconfiguredChannels')]
    public function test_unconfigured_channel_returns_not_configured_and_sends_nothing(array $overrides): void
    {
        $this->configureChannel($overrides);
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendText($conv, 'مرحبا');

        $this->assertBlocked($result, WhatsAppChannel::ERR_NOT_CONFIGURED, $conv);
    }

    #[Group('INV-05')]
    public function test_suspended_flag_in_settings_returns_sending_suspended(): void
    {
        $this->configureChannel(['wa_suspended' => true]);
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendText($conv, 'مرحبا');

        $this->assertBlocked($result, WhatsAppChannel::ERR_SUSPENDED, $conv);
    }

    #[Group('INV-05')]
    public function test_suspended_flag_in_config_returns_sending_suspended(): void
    {
        config(['services.whatsapp.suspended' => true]);
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendText($conv, 'مرحبا');

        $this->assertBlocked($result, WhatsAppChannel::ERR_SUSPENDED, $conv);
    }

    #[Group('INV-05')]
    public function test_closed_window_returns_window_expired(): void
    {
        $conv = WaConversation::factory()->windowClosed()->create();

        $result = WhatsAppChannel::sendText($conv, 'مرحبا');

        $this->assertBlocked($result, WhatsAppChannel::ERR_WINDOW, $conv);
    }

    #[Group('INV-05')]
    public function test_conversation_without_any_inbound_message_has_no_window(): void
    {
        $conv = WaConversation::factory()->create(['last_inbound_at' => null]);

        $result = WhatsAppChannel::sendText($conv, 'مرحبا');

        $this->assertBlocked($result, WhatsAppChannel::ERR_WINDOW, $conv);
    }

    #[Group('INV-05')]
    public function test_window_closes_exactly_24_hours_after_last_inbound(): void
    {
        $this->freezeSecond();
        $conv = WaConversation::factory()->create(['last_inbound_at' => now()->subDay()]);

        $result = WhatsAppChannel::sendText($conv, 'مرحبا');

        $this->assertBlocked($result, WhatsAppChannel::ERR_WINDOW, $conv);
    }

    #[Group('INV-05')]
    public function test_window_is_still_open_one_minute_before_24_hours(): void
    {
        $this->freezeSecond();
        $conv = WaConversation::factory()->create(['last_inbound_at' => now()->subDay()->addMinute()]);

        $result = WhatsAppChannel::sendText($conv, 'مرحبا');

        $this->assertTrue($result['ok']);
        Http::assertSentCount(1);
        $this->assertSame(1, WaMessage::count());
    }

    public static function sources(): array
    {
        return [
            'bot' => ['bot'],
            'staff' => ['staff'],
            'system' => ['system'],
        ];
    }

    #[Group('INV-05')]
    #[DataProvider('sources')]
    public function test_no_source_bypasses_the_window(string $source): void
    {
        $conv = WaConversation::factory()->windowClosed()->create();

        // مرسل «موظف» يرى المحادثة، حتى يُختبر قفل النافذة لا قفل السياج
        $staffId = $source === 'staff'
            ? \App\Models\User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create()->id
            : null;
        $result = WhatsAppChannel::sendText($conv, 'مرحبا', $source, $staffId);

        $this->assertBlocked($result, WhatsAppChannel::ERR_WINDOW, $conv);
    }

    public static function sendMethods(): array
    {
        return [
            'buttons' => [fn (WaConversation $c) => WhatsAppChannel::sendButtons($c, 'اختر', [['id' => 'offer:1', 'title' => 'العرض']])],
            'list' => [fn (WaConversation $c) => WhatsAppChannel::sendList($c, 'اختر', 'العروض', [['id' => 'offer:1', 'title' => 'العرض']])],
            'image' => [fn (WaConversation $c) => WhatsAppChannel::sendImage($c, 'https://example.com/card.png', 'بطاقة')],
        ];
    }

    #[Group('INV-05')]
    #[DataProvider('sendMethods')]
    public function test_every_send_method_goes_through_the_gates(\Closure $send): void
    {
        $conv = WaConversation::factory()->windowClosed()->create();

        $result = $send($conv);

        $this->assertBlocked($result, WhatsAppChannel::ERR_WINDOW, $conv);
    }

    #[Group('INV-05')]
    public function test_not_configured_wins_over_suspended(): void
    {
        $this->configureChannel(['wa_token' => null, 'wa_suspended' => true]);
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendText($conv, 'مرحبا');

        $this->assertBlocked($result, WhatsAppChannel::ERR_NOT_CONFIGURED, $conv);
    }

    #[Group('INV-05')]
    public function test_not_configured_wins_over_suspended_and_closed_window(): void
    {
        $this->configureChannel(['wa_phone_number_id' => null, 'wa_suspended' => true]);
        $conv = WaConversation::factory()->windowClosed()->create();

        $result = WhatsAppChannel::sendText($conv, 'مرحبا');

        $this->assertBlocked($result, WhatsAppChannel::ERR_NOT_CONFIGURED, $conv);
    }

    #[Group('INV-05')]
    public function test_suspended_wins_over_closed_window(): void
    {
        $this->configureChannel(['wa_suspended' => true]);
        $conv = WaConversation::factory()->windowClosed()->create();

        $result = WhatsAppChannel::sendText($conv, 'مرحبا');

        $this->assertBlocked($result, WhatsAppChannel::ERR_SUSPENDED, $conv);
    }
}
