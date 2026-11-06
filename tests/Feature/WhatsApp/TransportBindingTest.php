<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Services\WhatsApp\ChannelDoctor;
use App\Services\WhatsApp\OpenWa\OpenWaTransport;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\Transport\ChannelTransport;
use App\Services\WhatsApp\Transport\LogTransport;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الناقل (E23، ومنذ 2026-11-06 ChannelTransport إلى بوابة OpenWA): log بلا شبكة خارج الإنتاج وحده، ويُرفض في production
 * مهما كان الإعداد (OpenWaTransport ويُسجَّل حرجاً ويعلّمه doctor خطأ). والأقفال فوق الناقل: الإيقاف والرقم غير الجاهز
 * يرفضان قبل أي نداء.
 */
class TransportBindingTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private function bind(string $driver, ?string $env = null): ChannelTransport
    {
        config(['whatsapp.transport' => $driver]);
        if ($env !== null) {
            $this->app['env'] = $env;
        }
        $this->app->forgetInstance(ChannelTransport::class);

        return app(ChannelTransport::class);
    }

    private function doctorItem(string $code): ?array
    {
        return collect(ChannelDoctor::local())->firstWhere('code', $code);
    }

    public function test_the_default_is_the_openwa_gateway(): void
    {
        $this->assertInstanceOf(OpenWaTransport::class, $this->bind('openwa'));
        $this->assertSame('openwa', app(ChannelTransport::class)->name());
        $this->assertSame('ok', $this->doctorItem('TRANSPORT')['level']);
    }

    public function test_the_log_transport_sends_without_network_and_records_the_request(): void
    {
        $this->configureChannel();
        $transport = $this->bind('log');
        $this->assertInstanceOf(LogTransport::class, $transport);
        $conv = WaConversation::factory()->windowOpen()->create(['channel_id' => $this->testChannel()->id]);

        $res = WhatsAppChannel::sendText($conv, 'مرحباً', 'system', sendKey: SendKey::optout('wamid.TEST'));

        $this->assertTrue($res['ok']);
        $this->assertStringContainsString('_LOG', (string) $conv->messages()->latest('id')->value('wamid'));
        Http::assertNothingSent();
        $sent = collect($transport->requests())->firstWhere('method', 'POST');
        $this->assertSame('/messages/send-text', $sent['path']);
        $this->assertSame('مرحباً', $sent['body']['text']);
        $this->assertArrayHasKey('Idempotency-Key', $sent['headers']);
        $this->assertSame('warn', $this->doctorItem('TRANSPORT')['level']);
    }

    public function test_locks_stay_above_the_transport(): void
    {
        $this->configureChannel();
        $transport = $this->bind('log');
        $channel = $this->testChannel();

        $channel->forceFill(['status' => 'disconnected'])->save();
        $down = WhatsAppChannel::sendText(WaConversation::factory()->create(['channel_id' => $channel->id]), 'x', 'system', sendKey: SendKey::optout('wamid.A'));
        $this->assertSame(WhatsAppChannel::ERR_NOT_READY, $down['code']);

        $channel->forceFill(['status' => 'ready'])->save();
        WaBotSetting::current()->update(['wa_suspended' => true]);
        $suspended = WhatsAppChannel::sendText(WaConversation::factory()->create(['channel_id' => $channel->id]), 'x', 'system', sendKey: SendKey::optout('wamid.B'));
        $this->assertSame(WhatsAppChannel::ERR_SUSPENDED, $suspended['code']);

        $this->assertSame([], $transport->requests());
    }

    public function test_log_is_refused_in_production(): void
    {
        $this->captureLogs();

        $transport = $this->bind('log', 'production');

        $this->assertInstanceOf(OpenWaTransport::class, $transport);
        $this->assertLogged('critical', 'wa.transport_log_refused_in_production');
        $this->assertSame('error', $this->doctorItem('TRANSPORT')['level']);
    }

    public function test_an_unknown_driver_binds_the_gateway_and_warns(): void
    {
        $this->assertInstanceOf(OpenWaTransport::class, $this->bind('cloud'));
        $this->assertSame('warn', $this->doctorItem('TRANSPORT')['level']);
    }
}
