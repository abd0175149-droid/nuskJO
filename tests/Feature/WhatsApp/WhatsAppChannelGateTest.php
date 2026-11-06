<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Sending\FailureBreaker;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * أقفال WhatsAppChannel::gate وترتيبها منذ OpenWA (2026-11-06):
 *   1) للمحادثة رقم فعّال  2) لا إيقاف طارئ  3) الرقم غير موقوف ولا مقيّد  4) الرقم جاهز وقاطعه مغلق  5) العميل غير محظور.
 * لا نافذة 24 ساعة. البوابة مزيّفة في كل حالة، فلو تجاوز الكود قفلاً لسُجّل الطلب وفشل assertNothingSent.
 */
class WhatsAppChannelGateTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
        $this->fakeGateway();
        $this->channel = $this->testChannel();
    }

    private function conv(array $attrs = []): WaConversation
    {
        return WaConversation::factory()->windowOpen()->create(['channel_id' => $this->channel->id] + $attrs);
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

    #[Group('INV-05')]
    public function test_a_conversation_without_any_active_number_returns_no_channel(): void
    {
        $this->channel->forceFill(['is_active' => false])->save();
        WaChannel::forgetCache();
        $conv = $this->conv();

        $this->assertBlocked(WhatsAppChannel::sendText($conv, 'مرحبا'), WhatsAppChannel::ERR_NO_CHANNEL, $conv);
    }

    #[Group('INV-05')]
    public function test_a_conversation_without_a_number_is_not_guessed_when_two_numbers_exist(): void
    {
        $this->makeChannel();
        $conv = WaConversation::factory()->windowOpen()->create(['channel_id' => null]);

        $this->assertBlocked(WhatsAppChannel::sendText($conv, 'مرحبا'), WhatsAppChannel::ERR_NO_CHANNEL, $conv);
        $this->assertNull($conv->fresh()->channel_id);
    }

    public function test_a_conversation_without_a_number_adopts_the_only_number(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create(['channel_id' => null]);

        $this->assertTrue(WhatsAppChannel::sendText($conv, 'مرحبا')['ok']);
        $this->assertSame($this->channel->id, $conv->fresh()->channel_id);
    }

    #[Group('INV-05')]
    public function test_suspended_flag_in_settings_returns_sending_suspended(): void
    {
        $this->configureChannel(['wa_suspended' => true]);
        $conv = $this->conv();

        $this->assertBlocked(WhatsAppChannel::sendText($conv, 'مرحبا'), WhatsAppChannel::ERR_SUSPENDED, $conv);
    }

    #[Group('INV-05')]
    public function test_suspended_flag_in_config_returns_sending_suspended(): void
    {
        config(['services.whatsapp.suspended' => true]);
        $conv = $this->conv();

        $this->assertBlocked(WhatsAppChannel::sendText($conv, 'مرحبا'), WhatsAppChannel::ERR_SUSPENDED, $conv);
    }

    #[Group('INV-05')]
    public function test_a_paused_number_returns_channel_paused(): void
    {
        $this->channel->forceFill(['paused_until' => now()->addHour(), 'pause_reason' => 'manual'])->save();
        $conv = $this->conv();

        $this->assertBlocked(WhatsAppChannel::sendText($conv, 'مرحبا'), WhatsAppChannel::ERR_PAUSED, $conv);
    }

    #[Group('INV-05')]
    public function test_a_restricted_number_returns_channel_restricted(): void
    {
        $this->channel->forceFill(['paused_until' => now()->addDays(7), 'pause_reason' => 'restriction'])->save();
        $conv = $this->conv();

        $this->assertBlocked(WhatsAppChannel::sendText($conv, 'مرحبا'), 'CHANNEL_RESTRICTED', $conv);
    }

    public function test_an_expired_pause_no_longer_blocks(): void
    {
        $this->channel->forceFill(['paused_until' => now()->subMinute(), 'pause_reason' => 'manual'])->save();

        $this->assertTrue(WhatsAppChannel::sendText($this->conv(), 'مرحبا')['ok']);
    }

    public static function notReadyChannels(): array
    {
        return [
            'disconnected' => [['status' => 'disconnected']],
            'waiting for qr' => [['status' => 'qr_ready']],
            'no phone yet' => [['phone' => null]],
            'stale probe after failures' => [['last_probe_ok_at' => null, 'status_at' => null, 'probe_failures' => 2]],
        ];
    }

    #[Group('INV-05')]
    #[DataProvider('notReadyChannels')]
    public function test_a_number_that_is_not_ready_returns_channel_not_ready(array $state): void
    {
        $this->channel->forceFill($state)->save();
        $conv = $this->conv();

        $this->assertBlocked(WhatsAppChannel::sendText($conv, 'مرحبا'), WhatsAppChannel::ERR_NOT_READY, $conv);
    }

    #[Group('INV-05')]
    public function test_an_open_breaker_returns_channel_not_ready(): void
    {
        for ($i = 0; $i < FailureBreaker::threshold(); $i++) {
            FailureBreaker::failure($this->channel, 'GW_UNREACHABLE');
        }
        $conv = $this->conv();

        $this->assertBlocked(WhatsAppChannel::sendText($conv, 'مرحبا'), WhatsAppChannel::ERR_NOT_READY, $conv);
    }

    #[Group('INV-05')]
    public function test_a_blocked_customer_returns_blocked(): void
    {
        $conv = $this->conv(['blocked_at' => now()]);

        $this->assertBlocked(WhatsAppChannel::sendText($conv, 'مرحبا'), WhatsAppChannel::ERR_BLOCKED, $conv);
    }

    #[Group('INV-05')]
    public function test_no_channel_wins_over_suspended(): void
    {
        $this->configureChannel(['wa_suspended' => true]);
        $this->channel->forceFill(['is_active' => false])->save();
        $conv = $this->conv();

        $this->assertBlocked(WhatsAppChannel::sendText($conv, 'مرحبا'), WhatsAppChannel::ERR_NO_CHANNEL, $conv);
    }

    #[Group('INV-05')]
    public function test_suspended_wins_over_a_paused_and_disconnected_number(): void
    {
        $this->configureChannel(['wa_suspended' => true]);
        $this->channel->forceFill(['paused_until' => now()->addHour(), 'status' => 'disconnected'])->save();
        $conv = $this->conv();

        $this->assertBlocked(WhatsAppChannel::sendText($conv, 'مرحبا'), WhatsAppChannel::ERR_SUSPENDED, $conv);
    }

    #[Group('INV-05')]
    public function test_paused_wins_over_not_ready_and_not_ready_wins_over_blocked(): void
    {
        $this->channel->forceFill(['paused_until' => now()->addHour(), 'status' => 'disconnected'])->save();
        $conv = $this->conv(['blocked_at' => now()]);
        $this->assertBlocked(WhatsAppChannel::sendText($conv, 'مرحبا'), WhatsAppChannel::ERR_PAUSED, $conv);

        $this->channel->forceFill(['paused_until' => null])->save();
        $this->assertBlocked(WhatsAppChannel::sendText($conv->fresh(), 'مرحبا'), WhatsAppChannel::ERR_NOT_READY, $conv);
    }

    public static function sendMethods(): array
    {
        return [
            'text' => [fn (WaConversation $c) => WhatsAppChannel::sendText($c, 'مرحبا')],
            'buttons' => [fn (WaConversation $c) => WhatsAppChannel::sendButtons($c, 'اختر', [['id' => 'offer:1', 'title' => 'العرض']])],
            'list' => [fn (WaConversation $c) => WhatsAppChannel::sendList($c, 'اختر', 'العروض', [['id' => 'offer:1', 'title' => 'العرض']])],
            'image' => [fn (WaConversation $c) => WhatsAppChannel::sendImage($c, 'https://example.com/card.png', 'بطاقة')],
        ];
    }

    #[Group('INV-05')]
    #[DataProvider('sendMethods')]
    public function test_every_send_method_goes_through_the_gates(\Closure $send): void
    {
        $this->channel->forceFill(['status' => 'disconnected'])->save();
        $conv = $this->conv();

        $this->assertBlocked($send($conv), WhatsAppChannel::ERR_NOT_READY, $conv);
    }

    public static function sources(): array
    {
        return ['bot' => ['bot'], 'staff' => ['staff'], 'system' => ['system']];
    }

    /** لا نافذة 24 ساعة على البوابة: عميل كتب قبل يومين يصله الرد من كل مصدر */
    #[DataProvider('sources')]
    public function test_there_is_no_24_hour_window_for_any_source(string $source): void
    {
        $conv = WaConversation::factory()->windowClosed()->create(['channel_id' => $this->channel->id]);
        $staffId = $source === 'staff'
            ? User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create()->id
            : null;

        $result = WhatsAppChannel::sendText($conv, 'مرحبا', $source, $staffId);

        $this->assertTrue($result['ok']);
        $this->assertSame(['مرحبا'], $this->sentTexts());
        $this->assertSame(1, WaMessage::count());
    }
}
