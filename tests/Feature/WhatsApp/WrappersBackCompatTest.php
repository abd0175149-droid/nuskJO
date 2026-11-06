<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * عقد ثابت (مراجعة E32): sendText وsendImage غلافان فوق المغلف الموحّد. الحمولة المحفوظة بشكلها الداخلي (Cloud API) كما
 * كانت، والصف والنتيجة كما كانا، والطلب إلى البوابة (منذ 2026-11-06) ترجمتها في PayloadMapper. أي فرق هنا تغيير في عقد الإرسال.
 */
class WrappersBackCompatTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    private string $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->fakeGraph();
        $this->conv = WaConversation::factory()->windowOpen()->create();
        $this->chat = $this->conv->wa_phone.'@c.us';
    }

    private function stored(WaMessage $row): array
    {
        return array_diff_key((array) $row->payload, ['_req_hash' => 1]);
    }

    public function test_send_text_request_row_and_result_are_unchanged(): void
    {
        $text = str_repeat('ع', 4100);

        $result = WhatsAppChannel::sendText($this->conv, $text);

        $this->assertSame(['ok' => true, 'error' => null, 'code' => null, 'message_id' => 'wamid.OUT.1'],
            array_intersect_key($result, array_flip(['ok', 'error', 'code', 'message_id'])));
        $this->assertSame(['chatId' => $this->chat, 'text' => mb_substr($text, 0, 4096), 'linkPreview' => false], $this->gatewaySends()[0]['body']);
        $row = WaMessage::sole();
        // نص الصف الكامل كما كان (المرسل مقصوص إلى 4096)
        $this->assertSame([$text, 'text', 'bot', 'sent'], [$row->body, $row->msg_type, $row->source, $row->status]);
        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'to' => $this->chat,
            'recipient_type' => 'individual',
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4096)],
        ], $this->stored($row));
        $this->assertNull($row->media_id);
        $this->assertNull($row->reply_to_wamid);
    }

    public function test_send_image_by_link_request_row_and_result_are_unchanged(): void
    {
        $result = WhatsAppChannel::sendImage($this->conv, 'https://nusk.example/storage/offer-cards/offer-7.png', 'عمرة رمضان');

        $this->assertSame(['ok' => true, 'error' => null, 'code' => null, 'message_id' => 'wamid.OUT.1'],
            array_intersect_key($result, array_flip(['ok', 'error', 'code', 'message_id'])));
        $this->assertSame(['chatId' => $this->chat, 'url' => 'https://nusk.example/storage/offer-cards/offer-7.png', 'mimetype' => 'image/jpeg',
            'caption' => 'عمرة رمضان'], $this->gatewaySends()[0]['body']);
        $row = WaMessage::sole();
        $this->assertSame(['عمرة رمضان', 'image'], [$row->body, $row->msg_type]);
        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'to' => $this->chat,
            'recipient_type' => 'individual',
            'type' => 'image',
            'image' => ['link' => 'https://nusk.example/storage/offer-cards/offer-7.png', 'caption' => 'عمرة رمضان'],
        ], $this->stored($row));
        $this->assertNull($row->media_path);
        $this->assertNull($row->media_status);
    }

    public function test_send_image_without_caption_keeps_the_old_placeholder_and_no_caption_key(): void
    {
        WhatsAppChannel::sendImage($this->conv, 'https://nusk.example/storage/offer-cards/offer-7.png');
        WhatsAppChannel::sendImage($this->conv, 'https://nusk.example/storage/offer-cards/offer-7.png', '   ');

        foreach ($this->gatewaySends() as $send) {
            $this->assertArrayNotHasKey('caption', $send['body']);
        }
        $this->assertSame(['link' => 'https://nusk.example/storage/offer-cards/offer-7.png'], WaMessage::orderBy('id')->first()->payload['image']);
        $this->assertSame(['📷 صورة', '   '], WaMessage::orderBy('id')->pluck('body')->all());
    }

    public function test_local_failures_keep_the_five_key_contract(): void
    {
        $five = fn (array $r) => array_intersect_key($r, array_flip(['ok', 'error', 'code', 'message_id', 'meta_code']));
        $this->testChannel()->update(['paused_until' => now()->addHour()]);

        $this->assertSame(['ok' => false, 'error' => 'رقم الواتساب «'.$this->testChannel()->name.'» موقوف مؤقتاً.', 'code' => 'CHANNEL_PAUSED', 'message_id' => null, 'meta_code' => null],
            $five(WhatsAppChannel::sendText($this->conv, 'أهلاً')));
        $this->assertSame(['ok' => false, 'error' => 'رابط الصورة يجب أن يكون HTTPS.', 'code' => 'BAD_MEDIA_URL', 'message_id' => null, 'meta_code' => null],
            $five(WhatsAppChannel::sendImage($this->conv, 'http://nusk.example/x.png')));
        Http::assertNothingSent();
    }

    public function test_the_ledger_path_keeps_its_result_shape(): void
    {
        $result = WhatsAppChannel::sendText($this->conv, 'أهلاً', 'bot', sendKey: 'reply:1:1:text');

        $row = WaMessage::sole();
        $this->assertSame(['ok' => true, 'error' => null, 'code' => null, 'message_id' => 'wamid.OUT.1', 'meta_code' => null,
            'status' => 'sent', 'row_id' => $row->id, 'replayed' => false], $result);
        $this->assertSame(hash('sha256', $this->conv->id.'|text|أهلاً'), $row->payload['_req_hash']);
    }
}
