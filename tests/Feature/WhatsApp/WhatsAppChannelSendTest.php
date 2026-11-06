<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * عقد الإرسال إلى البوابة (منذ 2026-11-06): مسار جلسة الرقم ومفتاحه المحصور، وPOST، وشكل الجسم، وحفظ معرّف الرسالة
 * الراجع، وأثر رد الموظف على المحادثة. الحمولة المحفوظة بشكلها الداخلي (Cloud API) كما تفحصها السياسة.
 */
class WhatsAppChannelSendTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const SEND_BASE = 'http://nuskjo-openwa:2785/api/sessions/sess-1/messages/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
        $this->fakeGraph();
    }

    /** طلب الإرسال الوحيد الذي خرج إلى البوابة */
    private function sentRequest(): Request
    {
        Http::assertSentCount(1);

        return Http::recorded()->first()[0];
    }

    #[Group('INV-06')]
    public function test_send_text_posts_to_the_channel_session_with_its_scoped_key(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendText($conv, 'أهلاً، كيف نخدمك؟');

        $this->assertSame(['ok' => true, 'error' => null, 'code' => null, 'message_id' => 'wamid.OUT.1'],
            array_intersect_key($result, array_flip(['ok', 'error', 'code', 'message_id'])));

        $request = $this->sentRequest();
        $this->assertSame(self::SEND_BASE.'send-text', $request->url());
        $this->assertSame('POST', $request->method());
        $this->assertTrue($request->hasHeader('X-API-Key', 'key-1'));
        $this->assertTrue($request->isJson());
        $this->assertSame(['chatId' => $conv->wa_phone.'@c.us', 'text' => 'أهلاً، كيف نخدمك؟', 'linkPreview' => false], $request->data());
        $this->assertSame('nj-m-'.WaMessage::sole()->id, $request->header('Idempotency-Key')[0]);
    }

    #[Group('INV-06')]
    public function test_returned_message_id_is_stored_on_the_outbound_message(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();

        WhatsAppChannel::sendText($conv, 'أهلاً');

        $msg = WaMessage::sole();
        $this->assertSame('wamid.OUT.1', $msg->wamid);
        $this->assertSame($conv->id, $msg->conversation_id);
        $this->assertSame($this->testChannel()->id, $msg->channel_id);
        $this->assertSame('out', $msg->direction);
        $this->assertSame('bot', $msg->source);
        $this->assertSame('text', $msg->msg_type);
        $this->assertSame('sent', $msg->status);
        $this->assertSame('أهلاً', $msg->body);
        $this->assertNull($msg->error_message);
        $this->assertSame('أهلاً', $msg->payload['text']['body']);
        $this->assertSame($conv->wa_phone.'@c.us', $msg->payload['to']);
    }

    #[Group('INV-06')]
    public function test_successful_send_updates_conversation_timestamps_and_preview(): void
    {
        $this->freezeSecond();
        $conv = WaConversation::factory()->windowOpen()->create();

        WhatsAppChannel::sendText($conv, "  أهلاً بك  \n");

        $conv->refresh();
        $this->assertTrue($conv->last_outbound_at->equalTo(now()));
        $this->assertTrue($conv->last_message_at->equalTo(now()));
        $this->assertSame('أهلاً بك', $conv->last_message_preview);
        Http::assertSentCount(1);
    }

    #[Group('INV-06')]
    public function test_text_body_is_capped_at_4096_characters(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();

        WhatsAppChannel::sendText($conv, str_repeat('ع', 5000));

        $this->assertSame(4096, mb_strlen($this->sentRequest()['text']));
    }

    #[Group('INV-06')]
    public function test_staff_reply_pauses_bot_and_clears_attention(): void
    {
        $this->freezeSecond();
        // موظف يرى المحادثة (القفل 0 في WhatsAppChannel يرفض غيره)
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $conv = WaConversation::factory()->windowOpen()->create([
            'needs_attention' => true,
            'unread_count' => 4,
        ]);

        $result = WhatsAppChannel::sendText($conv, 'معك فريق المبيعات', 'staff', $staff->id);

        $this->assertTrue($result['ok']);
        $this->assertSame(self::SEND_BASE.'send-text', $this->sentRequest()->url());

        $conv->refresh();
        $this->assertTrue($conv->bot_paused_until->equalTo(now()->addMinutes(30)));
        $this->assertFalse($conv->needs_attention);
        $this->assertSame(0, $conv->unread_count);
        $this->assertFalse($conv->botActive());

        $msg = WaMessage::sole();
        $this->assertSame('staff', $msg->source);
        $this->assertSame($staff->id, $msg->staff_id);
        $this->assertSame('wamid.OUT.1', $msg->wamid);
    }

    #[Group('INV-06')]
    public function test_staff_pause_follows_configured_pause_minutes(): void
    {
        $this->freezeSecond();
        $this->configureChannel(['pause_minutes' => 45]);
        $conv = WaConversation::factory()->windowOpen()->create();

        WhatsAppChannel::sendText($conv, 'معك فريق المبيعات', 'staff', User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create()->id);

        Http::assertSentCount(1);
        $this->assertTrue($conv->fresh()->bot_paused_until->equalTo(now()->addMinutes(45)));
    }

    #[Group('INV-06')]
    public function test_bot_reply_does_not_pause_bot_or_clear_attention(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create([
            'needs_attention' => true,
            'unread_count' => 4,
        ]);

        WhatsAppChannel::sendText($conv, 'رد آلي', 'bot');

        Http::assertSentCount(1);
        $conv->refresh();
        $this->assertNull($conv->bot_paused_until);
        $this->assertTrue($conv->needs_attention);
        $this->assertSame(4, $conv->unread_count);
    }

    #[Group('INV-06')]
    public function test_send_buttons_keeps_at_most_three_options_and_sends_them_numbered(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendButtons($conv, 'أي عرض يناسبك؟', [
            ['id' => 'offer:12', 'title' => 'عمرة رمضان الاقتصادية جداً جداً'],
            ['id' => 'offer:13', 'title' => 'عمرة المولد'],
            ['id' => 'human', 'title' => 'موظف'],
            ['id' => 'offer:14', 'title' => 'زر رابع يجب أن يسقط'],
        ]);

        $this->assertSame('wamid.OUT.1', $result['message_id']);
        $request = $this->sentRequest();
        $this->assertSame(self::SEND_BASE.'send-text', $request->url());
        $this->assertSame($conv->wa_phone.'@c.us', $request['chatId']);
        $text = $request['text'];
        $this->assertStringStartsWith('أي عرض يناسبك؟', $text);
        $this->assertStringContainsString("\n2. عمرة المولد", $text);
        $this->assertStringContainsString("\n3. موظف", $text);
        $this->assertStringNotContainsString('زر رابع', $text);

        // المحفوظ بشكله الداخلي: المعرّفات للحل (ChoiceResolver) والعناوين المقصوصة
        $msg = WaMessage::sole();
        $this->assertSame('interactive', $msg->msg_type);
        $this->assertSame('button', $msg->payload['interactive']['type']);
        $buttons = $msg->payload['interactive']['action']['buttons'];
        $this->assertSame(['offer:12', 'offer:13', 'human'], array_column(array_column($buttons, 'reply'), 'id'));
        foreach ($buttons as $button) {
            $this->assertLessThanOrEqual(20, mb_strlen($button['reply']['title']));
        }
        $this->assertStringStartsWith($buttons[0]['reply']['title'], 'عمرة رمضان الاقتصادية جداً جداً');
    }

    #[Group('INV-06')]
    public function test_send_list_keeps_at_most_ten_rows(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $items = [];
        for ($i = 1; $i <= 12; $i++) {
            $items[] = [
                'id' => "offer:{$i}",
                'title' => "عرض رقم {$i} بعنوان طويل يتجاوز الحد",
                'description' => str_repeat('وصف ', 30),
            ];
        }

        WhatsAppChannel::sendList($conv, 'اختر العرض', 'عرض كل الخيارات المتاحة', $items);

        $request = $this->sentRequest();
        $this->assertSame(self::SEND_BASE.'send-text', $request->url());
        $this->assertStringContainsString("\n10. ", $request['text']);
        $this->assertStringNotContainsString("\n11. ", $request['text']);

        $payload = WaMessage::sole()->payload;
        $this->assertSame('list', $payload['interactive']['type']);
        $rows = $payload['interactive']['action']['sections'][0]['rows'];
        $this->assertCount(10, $rows);
        $this->assertSame('offer:1', $rows[0]['id']);
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual(24, mb_strlen($row['title']));
            $this->assertLessThanOrEqual(72, mb_strlen($row['description']));
        }
    }

    #[Group('INV-06')]
    public function test_send_image_passes_an_external_link_and_caption(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendImage($conv, 'https://nusk.example/storage/cards/12.png', 'عرض عمرة رمضان');

        $this->assertTrue($result['ok']);
        $request = $this->sentRequest();
        $this->assertSame(self::SEND_BASE.'send-image', $request->url());
        $this->assertSame(['chatId' => $conv->wa_phone.'@c.us', 'url' => 'https://nusk.example/storage/cards/12.png',
            'mimetype' => 'image/jpeg', 'caption' => 'عرض عمرة رمضان'], $request->data());

        $msg = WaMessage::sole();
        $this->assertSame('image', $msg->msg_type);
        $this->assertSame('عرض عمرة رمضان', $msg->body);
        $this->assertSame('wamid.OUT.1', $msg->wamid);
    }

    #[Group('INV-06')]
    public function test_send_image_on_our_storage_is_read_from_disk_not_fetched(): void
    {
        config(['app.url' => 'https://nusk.example']);
        $dir = storage_path('app/public/cards');
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/t-12.png', 'PNG-BYTES');
        $conv = WaConversation::factory()->windowOpen()->create();

        try {
            WhatsAppChannel::sendImage($conv, 'https://nusk.example/storage/cards/t-12.png', 'عرض');
        } finally {
            @unlink($dir.'/t-12.png');
        }

        $request = $this->sentRequest();
        $this->assertSame(base64_encode('PNG-BYTES'), $request['base64']);
        $this->assertArrayNotHasKey('url', $request->data());
    }

    #[Group('INV-06')]
    public function test_send_image_rejects_non_https_link_before_calling_the_gateway(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendImage($conv, 'http://nusk.example/storage/cards/12.png');

        $this->assertFalse($result['ok']);
        $this->assertSame('BAD_MEDIA_URL', $result['code']);
        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
    }

    #[Group('INV-06')]
    public function test_the_gateway_url_comes_from_config(): void
    {
        config(['whatsapp.openwa.url' => 'http://other-gateway:2785']);
        Http::fake(['other-gateway:2785/*' => Http::response(['messageId' => 'X.1'], 201)]);
        $conv = WaConversation::factory()->windowOpen()->create();

        WhatsAppChannel::sendText($conv, 'أهلاً');

        Http::assertSent(fn (Request $r) => $r->url() === 'http://other-gateway:2785/api/sessions/sess-1/messages/send-text');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'nuskjo-openwa'));
    }
}
