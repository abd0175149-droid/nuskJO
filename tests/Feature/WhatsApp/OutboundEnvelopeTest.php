<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Setting;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\IdentityMode;
use App\Services\WhatsApp\Outbound\OutboundMessage;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * المغلف الموحّد (E32.2): كل نوع يبني حمولة Cloud API الرسمية بالضبط عبر dispatch بأقفاله، والاقتباس من المحادثة نفسها
 * وحدها، والنوع غير المسجّل يُرفض بلا HTTP، والتعليق يُقص بالمحارف، والوجهة to أو recipient لا الاثنان، وأعمدة
 * media_* تُكتب للصادر.
 */
class OutboundEnvelopeTest extends TestCase
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

    private function sent(): array
    {
        Http::assertSentCount(1);

        return Http::recorded()->first()[0]->data();
    }

    public function test_each_type_builds_the_official_payload(): void
    {
        $expect = [
            [OutboundMessage::text('أهلاً'), ['text' => ['preview_url' => false, 'body' => 'أهلاً']]],
            [OutboundMessage::image('1001', 'صورة الحجز'), ['image' => ['id' => '1001', 'caption' => 'صورة الحجز']]],
            [OutboundMessage::document('1002', 'فاتورة-7.pdf', 'فاتورتك'), ['document' => ['id' => '1002', 'filename' => 'فاتورة-7.pdf', 'caption' => 'فاتورتك']]],
            [OutboundMessage::video('1003'), ['video' => ['id' => '1003']]],
        ];
        foreach ($expect as $i => [$message, $object]) {
            $this->assertTrue(WhatsAppChannel::send($this->conv, $message, 'bot')['ok']);
            $request = Http::recorded()[$i][0]->data();
            $type = array_key_first($object);
            $this->assertSame(['messaging_product' => 'whatsapp', 'to' => $this->conv->wa_phone, 'recipient_type' => 'individual', 'type' => $type] + $object, $request, $type);
        }

        $rows = WaMessage::orderBy('id')->get();
        $this->assertSame(['text', 'image', 'document', 'video'], $rows->pluck('msg_type')->all());
        $this->assertSame(['أهلاً', '📷 صورة: صورة الحجز', '📄 ملف: فاتورة-7.pdf: فاتورتك', '🎬 مقطع فيديو'], $rows->pluck('body')->all());
        $this->assertSame([null, '1001', '1002', '1003'], $rows->pluck('media_id')->all());
    }

    public function test_a_quote_is_kept_only_for_a_message_of_the_same_conversation(): void
    {
        WaMessage::factory()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.MINE']);
        WaMessage::factory()->create(['conversation_id' => WaConversation::factory()->create()->id, 'wamid' => 'wamid.OTHER']);

        WhatsAppChannel::send($this->conv, OutboundMessage::text('تفضّل')->withReplyTo('wamid.OTHER'), 'bot');
        WhatsAppChannel::send($this->conv, OutboundMessage::text('تفضّل')->withReplyTo('wamid.MINE'), 'bot');

        [$other, $mine] = [Http::recorded()[0][0]->data(), Http::recorded()[1][0]->data()];
        $this->assertArrayNotHasKey('context', $other);
        $this->assertSame(['message_id' => 'wamid.MINE'], $mine['context']);
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

    public function test_a_later_epic_registers_its_type_on_the_same_envelope(): void
    {
        // نوع بلا قاعدة محتوى خاصة (الموقع والبطاقات يطابقهما E33 مع الجدولين المعتمدين؛ ShareLocationTest)
        OutboundMessage::registerType('sticker',
            fn (OutboundMessage $m) => ['id' => $m->data['id']],
            fn (OutboundMessage $m) => '🏷️ '.$m->data['label']);
        try {
            $result = WhatsAppChannel::send($this->conv, OutboundMessage::custom('sticker', ['id' => '1001', 'label' => 'ملصق']), 'bot');
        } finally {
            OutboundMessage::forgetType('sticker');
        }

        $this->assertTrue($result['ok']);
        $this->assertSame(['id' => '1001'], $this->sent()['sticker']);
        $this->assertSame('🏷️ ملصق', WaMessage::sole()->body);
    }

    public function test_a_long_caption_is_cut_by_characters_without_breaking_utf8(): void
    {
        WhatsAppChannel::send($this->conv, OutboundMessage::image('1001', str_repeat('نُسك ', 300)), 'bot');

        $caption = $this->sent()['image']['caption'];
        $this->assertSame(1024, mb_strlen($caption));
        $this->assertTrue(mb_check_encoding($caption, 'UTF-8'));
    }

    public function test_the_target_is_to_or_recipient_never_both(): void
    {
        Setting::set('wa_identity_mode', IdentityMode::STAFF);
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $phoneless = WaConversation::factory()->windowOpen()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => 'US.13491208655302741918']);

        WhatsAppChannel::send($phoneless, OutboundMessage::document('1002', 'تذكرة.pdf'), 'staff', $staff->id);

        $request = $this->sent();
        $this->assertSame('US.13491208655302741918', $request['recipient']);
        $this->assertArrayNotHasKey('to', $request);
    }

    public function test_media_columns_are_written_for_the_outbound_row(): void
    {
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $this->conv->update(['needs_attention' => true, 'unread_count' => 3]);

        $result = WhatsAppChannel::send($this->conv, OutboundMessage::document('1002', 'visa.pdf'), 'staff', $staff->id, [
            'media' => ['mime' => 'application/pdf', 'sha256' => str_repeat('a', 64), 'size' => 2048, 'filename' => 'visa.pdf', 'path' => 'outbound/2026/10/x.pdf'],
        ]);

        $this->assertTrue($result['ok']);
        $row = WaMessage::sole();
        $this->assertSame(['1002', 'application/pdf', 2048, 'visa.pdf', 'outbound/2026/10/x.pdf', 'stored'],
            [$row->media_id, $row->media_mime, $row->media_size, $row->media_filename, $row->media_path, $row->media_status]);
        $this->assertNotNull($row->media_archived_at);
        // رد الموظف بملف كالرد النصي: يصفّر غير المقروء والتدخّل ويوقف البوت
        $this->conv->refresh();
        $this->assertSame([0, false], [$this->conv->unread_count, $this->conv->needs_attention]);
        $this->assertTrue($this->conv->bot_paused_until->isFuture());
    }

    public function test_locks_run_before_any_request(): void
    {
        $this->conv->update(['last_inbound_at' => now()->subDays(2)]);

        $result = WhatsAppChannel::send($this->conv, OutboundMessage::image('1001'), 'bot');

        $this->assertSame(WhatsAppChannel::ERR_WINDOW, $result['code']);
        Http::assertNothingSent();
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
