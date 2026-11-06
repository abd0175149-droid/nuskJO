<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Offer;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsMediaSamples;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * بطاقة العرض بالمعرّف (E32.11، العلم offer_card_by_media_id؛ منذ 2026-11-06 المعرّف نسخة على wa_media تُرسل base64):
 * البطاقة الكبيرة لا تُدّعى مرسلة، والنسخة تُجهَّز مرة ويُعاد استعمالها، ونسخة إثبات السعر على wa_media، والفشل بلا رجوع
 * إلى الرابط العام. والعلم مطفأ: الرابط على APP_URL/storage يُقرأ من القرص العام ولا تجلبه البوابة.
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

    private function fakeMeta(): void
    {
        $this->fakeGateway();
    }

    private function sendCard(WaConversation $conv): array
    {
        return BotTools::execute('send_offer_card', ['offer_id' => $this->offer->id], $conv);
    }

    public function test_the_card_is_staged_once_and_sent_by_id_with_a_proof_copy(): void
    {
        config(['features.env.offer_card_by_media_id' => true]);
        $this->fakeMeta();
        $first = WaConversation::factory()->windowOpen()->create();
        $second = WaConversation::factory()->windowOpen()->create();

        $this->assertTrue($this->sendCard($first)['sent']);
        $out = $this->sendCard($second);
        $this->assertTrue($out['sent'], json_encode($out, JSON_UNESCAPED_UNICODE));

        $this->assertCount(1, Storage::disk('wa_media')->allFiles('openwa-staging'), 'النسخة تُجهَّز مرة ويُعاد استعمالها');
        $sends = $this->gatewaySends();
        $this->assertSame(['send-image', 'send-image'], array_column($sends, 'path'));
        foreach ($sends as $send) {
            $this->assertSame([base64_encode($this->pngBytes()), $this->offer->title], [$send['body']['base64'], $send['body']['caption']]);
            $this->assertArrayNotHasKey('url', $send['body']);
        }

        $row = WaMessage::where('conversation_id', $first->id)->sole();
        $this->assertStringStartsWith('local:openwa-staging/', $row->media_id);
        $this->assertSame($row->media_id, WaMessage::where('conversation_id', $second->id)->sole()->media_id);
        $this->assertSame(['stored', 'image/png', $this->offer->title], [$row->media_status, $row->media_mime, $row->body]);
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

    public function test_a_failure_never_falls_back_to_the_public_link(): void
    {
        config(['features.env.offer_card_by_media_id' => true]);
        $this->fakeMeta();
        $this->testChannel()->update(['paused_until' => now()->addHour()]);

        $out = $this->sendCard(WaConversation::factory()->windowOpen()->create());

        $this->assertFalse($out['sent']);
        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
        $this->assertSame([], Storage::disk('wa_media')->allFiles());
    }

    public function test_with_the_flag_off_our_public_link_is_read_from_disk(): void
    {
        config(['app.url' => 'https://nusk.test']);
        $this->fakeMeta();

        $this->assertTrue($this->sendCard(WaConversation::factory()->windowOpen()->create())['sent']);

        $send = $this->gatewaySends()[0];
        $this->assertSame(base64_encode($this->pngBytes()), $send['body']['base64'], 'من القرص العام، فلا تجلبه البوابة عبر النفق');
        $this->assertArrayNotHasKey('url', $send['body']);
        $this->assertStringStartsWith('https://nusk.test/storage/offer-cards/', (string) WaMessage::sole()->payload['image']['link']);
        $this->assertNull(WaMessage::sole()->media_id);
        $this->assertSame([], Storage::disk('wa_media')->allFiles('openwa-staging'));
    }
}
