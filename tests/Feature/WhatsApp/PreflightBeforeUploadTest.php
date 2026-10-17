<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Setting;
use App\Models\User;
use App\Models\WaConversation;
use App\Services\WhatsApp\IdentityMode;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsMediaSamples;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * لا رفع قبل الأقفال (E32.3): نافذة مغلقة، أو إيقاف طارئ، أو موظف خارج النطاق، أو قناة غير مهيّأة، أو عميل بلا وجهة،
 * فلا طلب إلى /media أبداً، ولا يبقى ملف يتيم عند ميتا. preflight هو أقفال dispatch نفسها بلا إرسال.
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
            'window closed' => ['WINDOW_EXPIRED'],
            'sending suspended' => ['SENDING_SUSPENDED'],
            'staff out of scope' => ['OUT_OF_SCOPE'],
            'channel not configured' => ['CHANNEL_NOT_CONFIGURED'],
            'no recipient' => ['NO_RECIPIENT'],
        ];
    }

    #[DataProvider('locks')]
    public function test_no_upload_request_leaves_when_a_lock_fails(string $code): void
    {
        $staff = $this->staffWith('desk', ['whatsapp.view', 'whatsapp.reply'], ['visa']);
        $conv = $this->conversationTagged(['visa']);
        match ($code) {
            'WINDOW_EXPIRED' => $conv->update(['last_inbound_at' => now()->subHours(25)]),
            'SENDING_SUSPENDED' => $this->configureChannel(['wa_suspended' => true]),
            'OUT_OF_SCOPE' => $conv = $this->conversationTagged(['umrah']),
            'CHANNEL_NOT_CONFIGURED' => $this->configureChannel(['wa_token' => null]),
            'NO_RECIPIENT' => $conv->update(['phone' => null, 'wa_phone' => null, 'wa_user_id' => 'US.13491208655302741918']),
        };
        if ($code === 'NO_RECIPIENT') {
            Setting::set('wa_identity_mode', IdentityMode::STORE);
        }

        $this->assertSame($code, WhatsAppChannel::preflight($conv->fresh(), 'staff', $staff->id)['code']);
        $result = WhatsAppChannel::uploadMedia($conv->fresh(), $this->sampleFile($this->pdfBytes(), 'pdf'), 'application/pdf', 'x.pdf', 'staff', $staff->id);

        $this->assertSame([false, $code, null], [$result['ok'], $result['code'], $result['media_id']]);
        Http::assertNothingSent();
    }

    public function test_preflight_passes_for_a_reachable_conversation(): void
    {
        $staff = $this->staffWith('desk', ['whatsapp.view', 'whatsapp.reply'], ['visa']);

        $this->assertNull(WhatsAppChannel::preflight($this->conversationTagged(['visa']), 'staff', $staff->id));
        $this->assertNull(WhatsAppChannel::preflight(WaConversation::factory()->windowOpen()->create(), 'bot'));
        $this->assertSame('OUT_OF_SCOPE', WhatsAppChannel::preflight(WaConversation::factory()->windowOpen()->create(), 'staff', User::factory()->create()->id)['code']);
    }
}
