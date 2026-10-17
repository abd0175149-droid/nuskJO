<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Services\WhatsApp\ChannelDoctor;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\Transport\CloudApiTransport;
use App\Services\WhatsApp\Transport\LogTransport;
use App\Services\WhatsApp\Transport\WhatsAppTransport;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الناقل (E23): log بلا شبكة خارج الإنتاج وحده، ويُرفض في production مهما كان الإعداد (Cloud API ويُسجَّل حرجاً
 * ويعلّمه doctor خطأ). والأقفال فوق الناقل: النافذة المغلقة والإيقاف يرفضان قبل أي نداء.
 */
class TransportBindingTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private function bind(string $driver, ?string $env = null): WhatsAppTransport
    {
        config(['whatsapp.transport' => $driver]);
        if ($env !== null) {
            $this->app['env'] = $env;
        }
        $this->app->forgetInstance(WhatsAppTransport::class);

        return app(WhatsAppTransport::class);
    }

    private function doctorItem(string $code): ?array
    {
        return collect(ChannelDoctor::local())->firstWhere('code', $code);
    }

    public function test_the_default_is_the_official_cloud_api(): void
    {
        $this->assertInstanceOf(CloudApiTransport::class, $this->bind('cloud'));
        $this->assertSame('ok', $this->doctorItem('TRANSPORT')['level']);
    }

    public function test_the_log_transport_sends_without_network_and_records_the_request(): void
    {
        $this->configureChannel();
        $transport = $this->bind('log');
        $this->assertInstanceOf(LogTransport::class, $transport);
        $conv = WaConversation::factory()->windowOpen()->create();

        $res = WhatsAppChannel::sendText($conv, 'مرحباً', 'system', sendKey: SendKey::optout('wamid.TEST'));

        $this->assertTrue($res['ok']);
        $this->assertStringStartsWith('wamid.LOG.', (string) $conv->messages()->latest('id')->value('wamid'));
        Http::assertNothingSent();
        $this->assertSame(['106540352242922', 'messages'], $transport->requests()[0]['segments']);
        $this->assertSame('warn', $this->doctorItem('TRANSPORT')['level']);
    }

    public function test_locks_stay_above_the_transport(): void
    {
        $this->configureChannel();
        $transport = $this->bind('log');

        $closed = WhatsAppChannel::sendText(WaConversation::factory()->windowClosed()->create(), 'x', 'system', sendKey: SendKey::optout('wamid.A'));
        $this->assertSame(WhatsAppChannel::ERR_WINDOW, $closed['code']);

        WaBotSetting::current()->update(['wa_suspended' => true]);
        $suspended = WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'x', 'system', sendKey: SendKey::optout('wamid.B'));
        $this->assertSame(WhatsAppChannel::ERR_SUSPENDED, $suspended['code']);

        $this->assertSame([], $transport->requests());
    }

    public function test_log_is_refused_in_production(): void
    {
        $this->captureLogs();

        $transport = $this->bind('log', 'production');

        $this->assertInstanceOf(CloudApiTransport::class, $transport);
        $this->assertLogged('critical', 'wa.transport_log_refused_in_production');
        $item = $this->doctorItem('TRANSPORT');
        $this->assertSame('error', $item['level']);
    }

    public function test_reads_come_from_the_graph_fixtures(): void
    {
        $this->configureChannel();
        $transport = $this->bind('log');

        $res = $transport->request('GET', ['106540352242922'], ['fields' => 'health_status,quality_rating']);

        $this->assertSame('AVAILABLE', $res->json('health_status.can_send_message'));
        $this->assertSame([], $transport->request('GET', ['unknown-node'])->json());
        Http::assertNothingSent();
    }
}
