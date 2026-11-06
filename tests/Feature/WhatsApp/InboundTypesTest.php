<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * الأنواع الواردة (E11.4، منذ 2026-11-06 من البوابة): نص مقروء لكل نوع بدل «[type]»، والاقتباس والوسيط في أعمدتهما،
 * وبطاقة شخص ثالث لا تمس هوية المحادثة. تحديثات الهوية من ميتا (تغيّر الرقم، BSUID) أُزيلت مع ناقلها.
 */
class InboundTypesTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const PHONE = '16505551234';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        User::factory()->admin()->create();
    }

    private function send(array $over)
    {
        return $this->postOpenWa('message.received', $this->incoming(self::PHONE, '', $over));
    }

    private function last(): WaMessage
    {
        return WaMessage::latest('id')->first();
    }

    public function test_each_type_gets_a_readable_body(): void
    {
        $cases = [
            [['type' => 'location', 'location' => ['latitude' => 31.95, 'longitude' => 35.91, 'description' => 'المكتب', 'address' => 'عمّان']],
                '📍 المكتب، عمّان https://maps.google.com/?q=31.95,35.91'],
            [['type' => 'vcard', 'body' => "BEGIN:VCARD\nVERSION:3.0\nFN:سائق الحافلة\nTEL;waid=16505553333:+1 650-555-3333\nEND:VCARD"],
                '👤 بطاقة جهة اتصال: سائق الحافلة'],
            [['type' => 'order'], '🛒 طلب من الكتالوج'],
            [['type' => 'sticker', 'media' => ['mimetype' => 'image/webp']], '🏷️ ملصق'],
            [['type' => 'ptt', 'media' => ['mimetype' => 'audio/ogg; codecs=opus']], '🎤 رسالة صوتية'],
            [['type' => 'document', 'body' => 'جواز', 'media' => ['mimetype' => 'application/pdf', 'filename' => 'passport.pdf']], '📄 ملف: passport.pdf: جواز'],
            [['type' => 'something_new'], '⚠️ رسالة لا يعرضها واتساب ويب (something_new)'],
        ];

        foreach ($cases as [$over, $expected]) {
            $this->send($over)->assertOk();
            $this->assertSame($expected, $this->last()->body, $over['type']);
        }
        $this->assertStringNotContainsString('16505553333', (string) WaMessage::where('msg_type', 'contacts')->sole()->body, 'رقم الشخص الثالث لا يصل للمعاينة ولا للنموذج');
        $this->assertSame(self::PHONE, WaConversation::sole()->phone, 'بطاقة شخص ثالث لا تمس الهوية');
    }

    public function test_quotes_media_and_search_text_are_kept_in_their_columns(): void
    {
        $this->send([
            'id' => 'false_16505551234@c.us_IMG1', 'type' => 'image', 'body' => 'هل العُمرة متاحة؟',
            'quotedMessage' => ['id' => 'true_16505551234@c.us_QUOTED', 'body' => 'عرض العمرة'],
            'media' => ['mimetype' => 'image/jpeg'], 'hasMedia' => true,
        ])->assertOk();

        $m = $this->last();
        $this->assertSame('📷 صورة: هل العُمرة متاحة؟', $m->body);
        $this->assertSame(['true_16505551234@c.us_QUOTED', 'false_16505551234@c.us_IMG1', 'image/jpeg', $this->testChannel()->id],
            [$m->reply_to_wamid, $m->media_id, $m->media_mime, $m->channel_id]);
        $this->assertSame('📷 صوره: هل العمره متاحه؟', $m->body_norm);
    }

    public function test_a_base64_thumbnail_in_the_body_is_not_kept_as_a_caption(): void
    {
        $this->send(['type' => 'image', 'body' => str_repeat('A', 500), 'media' => ['mimetype' => 'image/jpeg']])->assertOk();

        $this->assertSame('📷 صورة', $this->last()->body);
    }
}
