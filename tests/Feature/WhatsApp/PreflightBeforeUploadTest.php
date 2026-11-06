<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsMediaSamples;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * لا تجهيز ملف قبل الأقفال (E32.3): رقم موقوف أو غير متصل أو بلا رقم، أو إيقاف طارئ، أو موظف خارج النطاق، أو عميل بلا
 * وجهة، فلا طلب ولا نسخة في openwa-staging. preflight هو أقفال dispatch نفسها بلا إرسال.
 */
class PreflightBeforeUploadTest extends TestCase
{
    use BuildsMediaSamples;
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wa_media');
        $this->configureChannel();
        Http::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownMediaSamples();
        parent::tearDown();
    }

    public static function locks(): array
    {
        return [
            'channel paused' => ['CHANNEL_PAUSED'],
            'channel not connected' => ['CHANNEL_NOT_READY'],
            'sending suspended' => ['SENDING_SUSPENDED'],
            'staff out of scope' => ['OUT_OF_SCOPE'],
            'no active channel' => ['NO_CHANNEL'],
            'no recipient' => ['NO_RECIPIENT'],
        ];
    }

    #[DataProvider('locks')]
    public function test_no_upload_request_leaves_when_a_lock_fails(string $code): void
    {
        $staff = $this->staffWith('desk', ['whatsapp.view', 'whatsapp.reply'], ['visa']);
        $conv = $this->conversationTagged(['visa']);
        match ($code) {
            'CHANNEL_PAUSED' => $this->testChannel()->update(['paused_until' => now()->addHour()]),
            'CHANNEL_NOT_READY' => $this->testChannel()->update(['status' => 'disconnected']),
            'SENDING_SUSPENDED' => $this->configureChannel(['wa_suspended' => true]),
            'OUT_OF_SCOPE' => $conv = $this->conversationTagged(['umrah']),
            'NO_CHANNEL' => $this->testChannel()->update(['is_active' => false]),
            'NO_RECIPIENT' => $conv->update(['phone' => null, 'wa_phone' => null, 'jid' => null]),
        };

        $this->assertSame($code, WhatsAppChannel::preflight($conv->fresh(), 'staff', $staff->id)['code']);
        $result = WhatsAppChannel::uploadMedia($conv->fresh(), $this->sampleFile($this->pdfBytes(), 'pdf'), 'application/pdf', 'x.pdf', 'staff', $staff->id);

        $this->assertSame([false, $code, null], [$result['ok'], $result['code'], $result['media_id']]);
        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('wa_media')->allFiles());
    }

    public function test_preflight_passes_for_a_reachable_conversation(): void
    {
        $staff = $this->staffWith('desk', ['whatsapp.view', 'whatsapp.reply'], ['visa']);

        $this->assertNull(WhatsAppChannel::preflight($this->conversationTagged(['visa']), 'staff', $staff->id));
        $this->assertNull(WhatsAppChannel::preflight(WaConversation::factory()->windowOpen()->create(), 'bot'));
        $this->assertSame('OUT_OF_SCOPE', WhatsAppChannel::preflight(WaConversation::factory()->windowOpen()->create(), 'staff', User::factory()->create()->id)['code']);
    }
}
