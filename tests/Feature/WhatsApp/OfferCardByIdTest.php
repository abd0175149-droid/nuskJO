<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Offer;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsMediaSamples;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * بطاقة العرض بالمعرّف (E32.11، العلم offer_card_by_media_id): البطاقة الكبيرة لا تُدّعى مرسلة، والمعرّف يُرفع مرة ويُعاد
 * استعماله، ونسخة إثبات السعر على wa_media، والفشل بلا رجوع إلى الرابط العام. والعلم مطفأ يعيد مسار الرابط كما هو.
 */
class OfferCardByIdTest extends TestCase
{
    use BuildsMediaSamples;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('https://nusk.test');
        URL::forceScheme('https');
        Storage::fake('public');
        Storage::fake('wa_media');
        $this->configureChannel();
        Storage::disk('public')->put('offer-cards/card-7.png', $this->pngBytes());
        $this->offer = Offer::factory()->ready()->create(['custom_card_path' => 'offer-cards/card-7.png']);
    }

    private function fakeMeta(bool $uploadOk = true): void
    {
        Http::fake([
            'graph.facebook.com/v25.0/106540352242922/media' => $uploadOk ? Http::response(['id' => '7700881122']) : Http::response(['error' => ['code' => 131053, 'message' => 'x']], 400),
            // دالة لا sequence: Http::fake يستدعي كل نمط مطابق، فالتسلسل يتقدم مع طلب /media أيضاً
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.CARD.'.uniqid()]]]),
        ]);
    }

    private function sendCard(WaConversation $conv): array
    {
        return BotTools::execute('send_offer_card', ['offer_id' => $this->offer->id], $conv);
    }

    public function test_the_card_is_uploaded_once_and_sent_by_id_with_a_proof_copy(): void
    {
        config(['features.env.offer_card_by_media_id' => true]);
        $this->fakeMeta();
        $first = WaConversation::factory()->windowOpen()->create();
        $second = WaConversation::factory()->windowOpen()->create();

        $this->assertTrue($this->sendCard($first)['sent']);
        $out = $this->sendCard($second);
        $this->assertTrue($out['sent'], json_encode($out, JSON_UNESCAPED_UNICODE));

        $uploads = Http::recorded()->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/media'));
        $this->assertCount(1, $uploads, 'المعرّف يُعاد استعماله');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['image']['id'] === '7700881122'
            && !isset($r['image']['link']) && $r['image']['caption'] === $this->offer->title);

        $row = WaMessage::where('conversation_id', $first->id)->sole();
        $this->assertSame(['7700881122', 'stored', 'image/png', $this->offer->title], [$row->media_id, $row->media_status, $row->media_mime, $row->body]);
        $this->assertSame($this->pngBytes(), Storage::disk('wa_media')->get($row->media_path));
    }

    public function test_a_card_over_5mb_is_not_claimed_as_sent_and_nothing_leaves(): void
    {
        config(['features.env.offer_card_by_media_id' => true]);
        Http::fake();
        Storage::disk('public')->put('offer-cards/card-7.png', $this->pngBytes(8, 5 * 1024 * 1024 + 100));

        $out = $this->sendCard(WaConversation::factory()->windowOpen()->create());

        $this->assertFalse($out['sent']);
        $this->assertStringContainsString('اذكر التفاصيل نصّاً', $out['note']);
        Http::assertNothingSent();
    }

    public function test_an_upload_failure_never_falls_back_to_the_public_link(): void
    {
        config(['features.env.offer_card_by_media_id' => true]);
        $this->fakeMeta(uploadOk: false);

        $out = $this->sendCard(WaConversation::factory()->windowOpen()->create());

        $this->assertFalse($out['sent']);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/messages'));
        $this->assertSame(0, WaMessage::count());
        $this->assertSame([], Storage::disk('wa_media')->allFiles());
    }

    public function test_with_the_flag_off_the_link_path_is_unchanged(): void
    {
        $this->fakeMeta();

        $this->assertTrue($this->sendCard(WaConversation::factory()->windowOpen()->create())['sent']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && str_starts_with((string) ($r['image']['link'] ?? ''), 'https://nusk.test/storage/offer-cards/'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/media'));
        $this->assertNull(WaMessage::sole()->media_id);
    }
}
