<?php

namespace Tests\Feature\WhatsApp\OpenWa;

use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * ثوابت القناة على بوابة OpenWA (tests/Invariants.php): INV-01 التوقيع بسر الرقم على البايتات الخام، وINV-03 حمولة جلسة
 * أخرى لا أثر لها، وINV-04 بعد التوقيع 200 دائماً، وINV-07 رفض البوابة ok=false برمز وصف failed بلا استثناء.
 */
class GatewayInvariantsTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
    }

    #[Group('INV-01')]
    public function test_only_a_payload_signed_with_the_number_secret_over_the_raw_bytes_is_accepted(): void
    {
        $ch = $this->testChannel();
        // نص عربي وشرطة مائلة: التوقيع على البايتات كما وصلت، لا على JSON أُعيد ترميزه
        $raw = json_encode(['event' => 'message.received', 'sessionId' => $ch->openwa_session_id, 'data' => $this->incoming('962791112233', 'سلام / مرحبا')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->postOpenWaRaw($raw, $ch, 'another-secret-another-secret')->assertForbidden();
        $this->postOpenWaRaw($raw, $ch, null)->assertForbidden();
        $ch->forceFill(['webhook_secret' => null])->save();
        $this->postOpenWaRaw($raw, $ch->fresh(), '')->assertForbidden();
        $this->assertSame(0, WaMessage::count());

        $ch->forceFill(['webhook_secret' => self::TEST_WEBHOOK_SECRET])->save();
        $this->postOpenWaRaw($raw, $ch->fresh())->assertOk();
        $this->assertSame('سلام / مرحبا', WaMessage::sole()->body);
    }

    #[Group('INV-03')]
    public function test_a_payload_for_another_session_creates_nothing(): void
    {
        $ch = $this->testChannel();
        $raw = json_encode(['event' => 'message.received', 'sessionId' => 'not-our-session', 'data' => $this->incoming('962791112233')]);

        $this->postOpenWaRaw($raw, $ch)->assertStatus(409);

        $this->assertSame(0, WaConversation::count());
        $this->assertSame(0, WaMessage::count());
        Queue::assertNothingPushed();
    }

    #[Group('INV-04')]
    public function test_after_the_signature_the_answer_is_200_even_for_broken_json(): void
    {
        $ch = $this->testChannel();

        $this->postOpenWaRaw('{"event": "message.received", "data": ', $ch)->assertOk();
        $this->postOpenWa('message.received', ['id' => null, 'from' => [], 'type' => ['x']])->assertOk();
        $this->postOpenWa('event.nobody.knows', ['x' => 1])->assertOk();
    }

    #[Group('INV-07')]
    public function test_a_gateway_refusal_returns_a_code_and_a_failed_row_without_an_exception(): void
    {
        $this->fakeGateway(['nuskjo-openwa:2785/api/sessions/*/messages/send-text' => Http::response(['message' => 'bad request'], 400)]);
        $conv = WaConversation::factory()->windowOpen()->create(['channel_id' => $this->testChannel()->id]);

        $res = WhatsAppChannel::sendText($conv, 'مرحبا', 'system', sendKey: 'inv07:1');

        $this->assertFalse($res['ok']);
        $this->assertSame('GW_REJECTED', $res['code']);
        $row = WaMessage::sole();
        $this->assertSame(WaMessage::STATUS_FAILED, $row->status);
        $this->assertSame('GW_REJECTED', $row->error_code);
    }
}
