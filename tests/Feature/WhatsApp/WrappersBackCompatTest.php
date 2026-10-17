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
 * عقد ثابت (مراجعة E32): sendText وsendImage صارتا غلافين فوق المغلف الموحّد، وتنتجان الطلب نفسه والصف نفسه والنتيجة
 * نفسها حرفياً كما قبل E32 (لقطات اختبارات E02 في WhatsAppChannelSendTest). أي فرق هنا تغيير في عقد الإرسال.
 */
class WrappersBackCompatTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->fakeGraph();
        $this->conv = WaConversation::factory()->windowOpen()->create();
    }

    public function test_send_text_request_row_and_result_are_unchanged(): void
    {
        $text = str_repeat('ع', 4100);

        $result = WhatsAppChannel::sendText($this->conv, $text);

        $this->assertSame(['ok' => true, 'error' => null, 'code' => null, 'message_id' => 'wamid.OUT.1'], $result);
        $request = Http::recorded()->first()[0]->data();
        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'to' => $this->conv->wa_phone,
            'recipient_type' => 'individual',
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4096)],
        ], $request);
        $row = WaMessage::sole();
        // نص الصف الكامل كما كان (المرسل مقصوص إلى 4096)
        $this->assertSame([$text, 'text', 'bot', 'sent', $request], [$row->body, $row->msg_type, $row->source, $row->status, $row->payload]);
        $this->assertNull($row->media_id);
        $this->assertNull($row->reply_to_wamid);
    }

    public function test_send_image_by_link_request_row_and_result_are_unchanged(): void
    {
        $result = WhatsAppChannel::sendImage($this->conv, 'https://nusk.example/storage/offer-cards/offer-7.png', 'عمرة رمضان');

        $this->assertSame(['ok' => true, 'error' => null, 'code' => null, 'message_id' => 'wamid.OUT.1'], $result);
        $request = Http::recorded()->first()[0]->data();
        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'to' => $this->conv->wa_phone,
            'recipient_type' => 'individual',
            'type' => 'image',
            'image' => ['link' => 'https://nusk.example/storage/offer-cards/offer-7.png', 'caption' => 'عمرة رمضان'],
        ], $request);
        $row = WaMessage::sole();
        $this->assertSame(['عمرة رمضان', 'image', $request], [$row->body, $row->msg_type, $row->payload]);
        $this->assertNull($row->media_path);
        $this->assertNull($row->media_status);
    }

    public function test_send_image_without_caption_keeps_the_old_placeholder_and_no_caption_key(): void
    {
        WhatsAppChannel::sendImage($this->conv, 'https://nusk.example/storage/offer-cards/offer-7.png');
        WhatsAppChannel::sendImage($this->conv, 'https://nusk.example/storage/offer-cards/offer-7.png', '   ');

        $this->assertSame(['link' => 'https://nusk.example/storage/offer-cards/offer-7.png'], Http::recorded()[0][0]->data()['image']);
        $this->assertSame(['link' => 'https://nusk.example/storage/offer-cards/offer-7.png'], Http::recorded()[1][0]->data()['image']);
        $this->assertSame(['📷 صورة', '   '], WaMessage::orderBy('id')->pluck('body')->all());
    }

    public function test_local_failures_keep_the_five_key_contract(): void
    {
        $this->conv->update(['last_inbound_at' => now()->subDays(2)]);

        $this->assertSame(['ok' => false, 'error' => 'انتهت نافذة الـ24 ساعة — لا يمكن الإرسال حتى يراسلك العميل من جديد.', 'code' => 'WINDOW_EXPIRED', 'message_id' => null, 'meta_code' => null],
            WhatsAppChannel::sendText($this->conv, 'أهلاً'));
        $this->assertSame(['ok' => false, 'error' => 'رابط الصورة يجب أن يكون HTTPS ليقبله واتساب.', 'code' => 'BAD_MEDIA_URL', 'message_id' => null, 'meta_code' => null],
            WhatsAppChannel::sendImage($this->conv, 'http://nusk.example/x.png'));
        Http::assertNothingSent();
    }

    public function test_the_ledger_path_keeps_its_result_shape(): void
    {
        config(['features.env.send_ledger' => true]);

        $result = WhatsAppChannel::sendText($this->conv, 'أهلاً', 'bot', sendKey: 'reply:1:1:text');

        $row = WaMessage::sole();
        $this->assertSame(['ok' => true, 'error' => null, 'code' => null, 'message_id' => 'wamid.OUT.1', 'meta_code' => null,
            'status' => 'sent', 'row_id' => $row->id, 'replayed' => false], $result);
        $this->assertSame(hash('sha256', $this->conv->id.'|text|أهلاً'), $row->payload['_req_hash']);
    }
}
