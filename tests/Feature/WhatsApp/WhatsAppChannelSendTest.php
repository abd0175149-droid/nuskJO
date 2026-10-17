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
 * عقد الإرسال إلى Graph: الرابط والنسخة من config، وPOST، وBearer، وشكل الجسم،
 * وحفظ wamid الراجع، وأثر رد الموظف على المحادثة.
 */
class WhatsAppChannelSendTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const SEND_URL = 'https://graph.facebook.com/v25.0/106540352242922/messages';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
        $this->fakeGraph();
    }

    /** الطلب الوحيد الذي خرج إلى Graph */
    private function sentRequest(): Request
    {
        Http::assertSentCount(1);

        return Http::recorded()->first()[0];
    }

    #[Group('INV-06')]
    public function test_send_text_posts_to_graph_with_bearer_token_and_text_body(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendText($conv, 'أهلاً، كيف نخدمك؟');

        $this->assertSame(['ok' => true, 'error' => null, 'code' => null, 'message_id' => 'wamid.OUT.1'], $result);

        Http::assertSent(fn (Request $r) => $r->url() === self::SEND_URL
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer test-token')
            && $r->isJson());

        $request = $this->sentRequest();
        $this->assertSame('whatsapp', $request['messaging_product']);
        $this->assertSame('individual', $request['recipient_type']);
        $this->assertSame($conv->wa_phone, $request['to']);
        $this->assertSame('text', $request['type']);
        $this->assertSame('أهلاً، كيف نخدمك؟', $request['text']['body']);
        $this->assertFalse($request['text']['preview_url']);
    }

    #[Group('INV-06')]
    public function test_returned_wamid_is_stored_on_the_outbound_message(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();

        WhatsAppChannel::sendText($conv, 'أهلاً');

        $msg = WaMessage::sole();
        $this->assertSame('wamid.OUT.1', $msg->wamid);
        $this->assertSame($conv->id, $msg->conversation_id);
        $this->assertSame('out', $msg->direction);
        $this->assertSame('bot', $msg->source);
        $this->assertSame('text', $msg->msg_type);
        $this->assertSame('sent', $msg->status);
        $this->assertSame('أهلاً', $msg->body);
        $this->assertNull($msg->error_message);
        $this->assertSame($this->sentRequest()->data(), $msg->payload);
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

        $this->assertSame(4096, mb_strlen($this->sentRequest()['text']['body']));
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
        Http::assertSent(fn (Request $r) => $r->url() === self::SEND_URL);

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
    public function test_send_buttons_keeps_at_most_three_buttons_with_20_character_titles(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendButtons($conv, 'أي عرض يناسبك؟', [
            ['id' => 'offer:12', 'title' => 'عمرة رمضان الاقتصادية جداً جداً'],
            ['id' => 'offer:13', 'title' => 'عمرة المولد'],
            ['id' => 'human', 'title' => 'موظف'],
            ['id' => 'offer:14', 'title' => 'زر رابع يجب أن يسقط'],
        ]);

        $this->assertSame('wamid.OUT.1', $result['message_id']);
        Http::assertSent(fn (Request $r) => $r->url() === self::SEND_URL
            && $r->hasHeader('Authorization', 'Bearer test-token'));

        $request = $this->sentRequest();
        $this->assertSame($conv->wa_phone, $request['to']);
        $this->assertSame('interactive', $request['type']);
        $this->assertSame('button', $request['interactive']['type']);
        $this->assertSame('أي عرض يناسبك؟', $request['interactive']['body']['text']);

        $buttons = $request['interactive']['action']['buttons'];
        $this->assertCount(3, $buttons);
        $this->assertSame(['offer:12', 'offer:13', 'human'], array_column(array_column($buttons, 'reply'), 'id'));
        foreach ($buttons as $button) {
            $this->assertSame('reply', $button['type']);
            $this->assertLessThanOrEqual(20, mb_strlen($button['reply']['title']));
        }
        $this->assertSame(20, mb_strlen($buttons[0]['reply']['title']));
        $this->assertStringStartsWith($buttons[0]['reply']['title'], 'عمرة رمضان الاقتصادية جداً جداً');

        $this->assertSame('interactive', WaMessage::sole()->msg_type);
    }

    #[Group('INV-06')]
    public function test_send_list_keeps_at_most_ten_rows_within_meta_limits(): void
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
        $this->assertSame(self::SEND_URL, $request->url());
        $this->assertSame('interactive', $request['type']);
        $this->assertSame('list', $request['interactive']['type']);
        $this->assertLessThanOrEqual(20, mb_strlen($request['interactive']['action']['button']));

        $rows = $request['interactive']['action']['sections'][0]['rows'];
        $this->assertCount(10, $rows);
        $this->assertSame('offer:1', $rows[0]['id']);
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual(24, mb_strlen($row['title']));
            $this->assertLessThanOrEqual(72, mb_strlen($row['description']));
        }
    }

    #[Group('INV-06')]
    public function test_send_image_posts_link_and_caption(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendImage($conv, 'https://nusk.example/storage/cards/12.png', 'عرض عمرة رمضان');

        $this->assertTrue($result['ok']);
        $request = $this->sentRequest();
        $this->assertSame(self::SEND_URL, $request->url());
        $this->assertSame('image', $request['type']);
        $this->assertSame(['link' => 'https://nusk.example/storage/cards/12.png', 'caption' => 'عرض عمرة رمضان'], $request['image']);

        $msg = WaMessage::sole();
        $this->assertSame('image', $msg->msg_type);
        $this->assertSame('عرض عمرة رمضان', $msg->body);
        $this->assertSame('wamid.OUT.1', $msg->wamid);
    }

    #[Group('INV-06')]
    public function test_send_image_rejects_non_https_link_before_calling_meta(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendImage($conv, 'http://nusk.example/storage/cards/12.png');

        $this->assertFalse($result['ok']);
        $this->assertSame('BAD_MEDIA_URL', $result['code']);
        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
    }

    #[Group('INV-06')]
    public function test_graph_version_comes_from_config(): void
    {
        config(['services.whatsapp.graph_version' => 'v26.0']);
        $conv = WaConversation::factory()->windowOpen()->create();

        WhatsAppChannel::sendText($conv, 'أهلاً');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v26.0/106540352242922/messages');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/v25.0/'));
    }

    #[Group('INV-06')]
    public function test_environment_credentials_take_precedence_over_stored_ones(): void
    {
        config([
            'services.whatsapp.token' => 'env-token',
            'services.whatsapp.phone_number_id' => '200000000000001',
        ]);
        $conv = WaConversation::factory()->windowOpen()->create();

        WhatsAppChannel::sendText($conv, 'أهلاً');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v25.0/200000000000001/messages'
            && $r->hasHeader('Authorization', 'Bearer env-token'));
        Http::assertSentCount(1);
    }
}
