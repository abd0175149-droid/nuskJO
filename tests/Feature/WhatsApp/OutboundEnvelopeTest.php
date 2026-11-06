<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Outbound\OutboundMessage;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * المغلف الموحّد (E32.2، منذ 2026-11-06 إلى البوابة): كل نوع يصل البوابة بمساره وجسمه عبر dispatch بأقفاله، والملف من
 * القرص الخاص base64، والاقتباس من المحادثة نفسها وحدها، والنوع الذي لا تحمله البوابة يُرفض بلا HTTP، والتعليق يُقص
 * بالمحارف، والوجهة jid المحادثة، وأعمدة media_* تُكتب للصادر.
 */
class OutboundEnvelopeTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wa_media');
        Storage::disk('wa_media')->put('t/1001.jpg', 'IMG');
        Storage::disk('wa_media')->put('t/1002.pdf', 'PDF');
        Storage::disk('wa_media')->put('t/1003.mp4', 'MP4');
        $this->configureChannel();
        $this->fakeGraph();
        $this->conv = WaConversation::factory()->windowOpen()->create();
    }

    private function sent(): array
    {
        $sends = $this->gatewaySends();
        $this->assertCount(1, $sends);

        return $sends[0]['body'];
    }

    public function test_each_type_reaches_the_gateway_with_its_path_and_body(): void
    {
        $chat = $this->conv->wa_phone.'@c.us';
        $expect = [
            [OutboundMessage::text('أهلاً'), 'send-text', ['chatId' => $chat, 'text' => 'أهلاً', 'linkPreview' => false]],
            [OutboundMessage::image('local:t/1001.jpg', 'صورة الحجز'), 'send-image',
                ['chatId' => $chat, 'base64' => base64_encode('IMG'), 'mimetype' => 'image/jpeg', 'caption' => 'صورة الحجز']],
            [OutboundMessage::document('local:t/1002.pdf', 'فاتورة-7.pdf', 'فاتورتك'), 'send-document',
                ['chatId' => $chat, 'base64' => base64_encode('PDF'), 'mimetype' => 'application/pdf', 'filename' => 'فاتورة-7.pdf', 'caption' => 'فاتورتك']],
            [OutboundMessage::video('local:t/1003.mp4'), 'send-video', ['chatId' => $chat, 'base64' => base64_encode('MP4'), 'mimetype' => 'video/mp4']],
        ];
        foreach ($expect as [$message]) {
            $this->assertTrue(WhatsAppChannel::send($this->conv, $message, 'bot')['ok']);
        }
        $sends = $this->gatewaySends();
        foreach ($expect as $i => [, $path, $body]) {
            $this->assertSame($path, $sends[$i]['path']);
            $this->assertSame($body, $sends[$i]['body'], $path);
        }

        $rows = WaMessage::orderBy('id')->get();
        $this->assertSame(['text', 'image', 'document', 'video'], $rows->pluck('msg_type')->all());
        $this->assertSame(['أهلاً', '📷 صورة: صورة الحجز', '📄 ملف: فاتورة-7.pdf: فاتورتك', '🎬 مقطع فيديو'], $rows->pluck('body')->all());
        $this->assertSame([null, 'local:t/1001.jpg', 'local:t/1002.pdf', 'local:t/1003.mp4'], $rows->pluck('media_id')->all());
    }

    public function test_a_quote_is_kept_only_for_a_message_of_the_same_conversation(): void
    {
        WaMessage::factory()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.MINE']);
        WaMessage::factory()->create(['conversation_id' => WaConversation::factory()->create()->id, 'wamid' => 'wamid.OTHER']);

        WhatsAppChannel::send($this->conv, OutboundMessage::text('تفضّل')->withReplyTo('wamid.OTHER'), 'bot');
        WhatsAppChannel::send($this->conv, OutboundMessage::text('تفضّل')->withReplyTo('wamid.MINE'), 'bot');

        [$other, $mine] = array_column($this->gatewaySends(), 'body');
        $this->assertArrayNotHasKey('quotedMessageId', $other);
        $this->assertSame('wamid.MINE', $mine['quotedMessageId']);
        $this->assertSame([null, 'wamid.MINE'], WaMessage::where('direction', 'out')->orderBy('id')->pluck('reply_to_wamid')->all());
    }

    public function test_an_unregistered_type_is_unsupported_without_any_request(): void
    {
        // نوع لم يسجّله أحد (location وcontacts وreaction تسجّلها E33 بمصدر معتمد)
        $result = WhatsAppChannel::send($this->conv, OutboundMessage::custom('sticker', ['id' => '1001']), 'bot');

        $this->assertSame([false, WhatsAppChannel::ERR_UNSUPPORTED], [$result['ok'], $result['code']]);
        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
    }

    public function test_a_registered_type_the_gateway_cannot_carry_fails_without_a_request(): void
    {
        OutboundMessage::registerType('sticker',
            fn (OutboundMessage $m) => ['id' => $m->data['id']],
            fn (OutboundMessage $m) => '🏷️ '.$m->data['label']);
        try {
            $result = WhatsAppChannel::send($this->conv, OutboundMessage::custom('sticker', ['id' => '1001', 'label' => 'ملصق']), 'bot');
        } finally {
            OutboundMessage::forgetType('sticker');
        }

        $this->assertSame([false, 'UNSUPPORTED', 'failed'], [$result['ok'], $result['code'], $result['status']]);
        Http::assertNothingSent();
        $this->assertSame(['failed', '🏷️ ملصق'], [WaMessage::sole()->status, WaMessage::sole()->body]);
    }

    public function test_a_long_caption_is_cut_by_characters_without_breaking_utf8(): void
    {
        WhatsAppChannel::send($this->conv, OutboundMessage::image('local:t/1001.jpg', str_repeat('نُسك ', 300)), 'bot');

        $caption = $this->sent()['caption'];
        $this->assertSame(1024, mb_strlen($caption));
        $this->assertTrue(mb_check_encoding($caption, 'UTF-8'));
    }

    public function test_the_target_is_the_stored_jid_else_the_phone(): void
    {
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $hidden = WaConversation::factory()->windowOpen()->create(['phone' => null, 'wa_phone' => null, 'jid' => '13491208655302741918@lid',
            'channel_id' => $this->testChannel()->id]);

        WhatsAppChannel::send($hidden, OutboundMessage::document('local:t/1002.pdf', 'تذكرة.pdf'), 'staff', $staff->id);
        WhatsAppChannel::send($this->conv, OutboundMessage::text('أهلاً'), 'bot');

        $this->assertSame(['13491208655302741918@lid', $this->conv->wa_phone.'@c.us'], array_column(array_column($this->gatewaySends(), 'body'), 'chatId'));
    }

    public function test_media_columns_are_written_for_the_outbound_row(): void
    {
        Storage::disk('wa_media')->put('outbound/2026/10/x.pdf', 'VISA');
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $this->conv->update(['needs_attention' => true, 'unread_count' => 3]);

        $result = WhatsAppChannel::send($this->conv, OutboundMessage::document('local:outbound/2026/10/x.pdf', 'visa.pdf'), 'staff', $staff->id, [
            'media' => ['mime' => 'application/pdf', 'sha256' => str_repeat('a', 64), 'size' => 2048, 'filename' => 'visa.pdf', 'path' => 'outbound/2026/10/x.pdf'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(base64_encode('VISA'), $this->sent()['base64']);
        $row = WaMessage::sole();
        $this->assertSame(['local:outbound/2026/10/x.pdf', 'application/pdf', 2048, 'visa.pdf', 'outbound/2026/10/x.pdf', 'stored'],
            [$row->media_id, $row->media_mime, $row->media_size, $row->media_filename, $row->media_path, $row->media_status]);
        $this->assertNotNull($row->media_archived_at);
        // رد الموظف بملف كالرد النصي: يصفّر غير المقروء والتدخّل ويوقف البوت
        $this->conv->refresh();
        $this->assertSame([0, false], [$this->conv->unread_count, $this->conv->needs_attention]);
        $this->assertTrue($this->conv->bot_paused_until->isFuture());
    }

    public function test_a_missing_file_fails_before_any_request(): void
    {
        $result = WhatsAppChannel::send($this->conv, OutboundMessage::image('local:t/gone.jpg'), 'bot');

        $this->assertSame([false, 'MEDIA_FILE_MISSING'], [$result['ok'], $result['code']]);
        Http::assertNothingSent();
        $this->assertSame('failed', WaMessage::sole()->status);
    }

    public function test_locks_run_before_any_request(): void
    {
        $this->testChannel()->update(['paused_until' => now()->addHour()]);

        $result = WhatsAppChannel::send($this->conv, OutboundMessage::image('local:t/1001.jpg'), 'bot');

        $this->assertSame(WhatsAppChannel::ERR_PAUSED, $result['code']);
        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
    }

    public function test_a_document_needs_an_uploaded_id(): void
    {
        $result = WhatsAppChannel::send($this->conv, OutboundMessage::document(null, 'x.pdf'), 'bot');

        $this->assertSame('MEDIA_ID_MISSING', $result['code']);
        Http::assertNothingSent();
    }

    public function test_file_names_are_cleaned_for_the_customer(): void
    {
        $this->assertSame('a b c.pdf', OutboundMessage::filename("a/b\\c\u{202E}.pdf", 'pdf'));
        $this->assertSame('ملف.jpg', OutboundMessage::filename('...', 'jpg'));
        $this->assertSame(200, mb_strlen(OutboundMessage::filename(str_repeat('ت', 300).'.pdf', 'pdf')));
        $this->assertSame('report.docx', OutboundMessage::document('1', 'report.docx')->filename);
    }
}
