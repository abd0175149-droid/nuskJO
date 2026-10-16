<?php

namespace Tests\Feature\Offers;

use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\User;
use App\Models\WaQrLink;
use App\Services\OfferCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * بطاقة الحالة 9:16 (E38.6): البيانات من القائمة البيضاء وحدها والقالب لا يستقبل نموذج العرض، والتقادم بالفحص نفسه،
 * والتنزيل يعيد التوليد إن قدُمت، ولا بطاقة لعرض لا يقرؤه البوت. والرمز على بطاقة A4 بقرار لكل عرض.
 * لا كروميوم في الاختبار: مصيّر بديل يكتب PNG ويسجّل المقاس.
 */
class StoryCardTest extends TestCase
{
    use RefreshDatabase;

    private array $shots = [];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['features.env.offer_story_card' => true]);
        $this->admin = User::factory()->admin()->create();
        $this->app->instance('offer-card.renderer', function (string $html, string $png, int $w, int $h) {
            $this->shots[] = [$w, $h, (string) file_get_contents($html)];
            $img = imagecreatetruecolor(48, 48);
            for ($i = 0; $i < 48 * 48; $i++) {
                imagesetpixel($img, $i % 48, intdiv($i, 48), random_int(0, 0xFFFFFF));
            }
            imagepng($img, $png);
        });
    }

    private function keys(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[] = $prefix.$k;
            if (is_array($v)) {
                $out = [...$out, ...$this->keys($v, $prefix.$k.'.')];
            }
        }

        return $out;
    }

    public function test_story_data_is_the_bot_whitelist_and_the_template_never_sees_the_model(): void
    {
        $offer = Offer::factory()->withCanaryInternals()->create(['includes' => ['التأشيرة', 'النقل', 'الزيارة', 'الفندق', 'الهدية']]);

        $data = OfferCardService::storyData($offer);

        $this->assertSame(['title', 'category', 'nights', 'airline', 'trip_date', 'valid_to', 'includes', 'price_from', 'company',
            'logoUri', 'heroUri', 'ref', 'short_link'], array_keys($data));
        $this->assertSame([], array_values(array_filter($this->keys($data), fn ($k) => preg_match('/agent|cost|rate|margin|seats|profit|capacity/i', $k))));
        $this->assertCount(4, $data['includes']);
        $this->assertSame('N-'.$offer->id, $data['ref']);
        $html = OfferCardService::storyHtml($offer);
        foreach (['777.777', '888.888', 'CANARY-COST', '9137'] as $canary) {
            $this->assertStringNotContainsString($canary, $html);
        }
        $this->assertStringContainsString("default-src 'none'; img-src data:;", $html);
        $this->assertDoesNotMatchRegularExpression('/(src|href)="https?:/i', $html);
        $this->assertStringNotContainsString('$offer->', (string) file_get_contents(resource_path('views/offers/card-story.blade.php')));
    }

    public function test_generation_is_9_by_16_and_a_price_change_makes_it_stale_so_the_download_regenerates(): void
    {
        $offer = Offer::factory()->create();

        $this->actingAs($this->admin)->postJson("/offers/{$offer->id}/story")->assertOk();
        $offer->refresh();
        $this->assertSame([1080, 1920], array_slice($this->shots[0], 0, 2));
        Storage::disk('public')->assertExists($offer->story_card_path);
        $this->assertFalse($offer->storyIsStale());
        $this->assertFalse(AuditLog::where('action', 'offer.story')->sole()->changes['auto']);

        $this->travel(5)->minutes();
        OfferOption::where('offer_id', $offer->id)->first()->update(['prices' => ['double' => 260, 'triple' => 230]]);
        $this->assertTrue($offer->fresh()->storyIsStale());

        $old = $offer->story_card_path;
        $this->actingAs($this->admin)->get("/offers/{$offer->id}/story/download")
            ->assertOk()->assertDownload("nusk-offer-{$offer->id}-story.png");
        $this->assertCount(2, $this->shots, 'أُعيد التوليد قبل التنزيل');
        Storage::disk('public')->assertMissing($old);
        $this->assertFalse($offer->fresh()->storyIsStale());
    }

    public function test_the_a4_staleness_is_unchanged(): void
    {
        $offer = Offer::factory()->create();
        $this->assertTrue($offer->cardIsStale(), 'بلا بطاقة');

        $offer->forceFill(['card_path' => 'offer-cards/x.png', 'card_generated_at' => now()->addMinute()])->saveQuietly();
        $this->assertFalse($offer->fresh()->cardIsStale());

        $this->travel(5)->minutes();
        OfferOption::where('offer_id', $offer->id)->first()->update(['sell_flat' => 5]);
        $this->assertTrue($offer->fresh()->cardIsStale());

        $offer->forceFill(['custom_card_path' => 'offer-cards/manual.png'])->saveQuietly();
        $this->assertFalse($offer->fresh()->cardIsStale(), 'المرفوعة يدوياً لا تُولَّد');
    }

    public function test_an_offer_the_bot_cannot_see_gets_no_story(): void
    {
        $offer = Offer::factory()->create(['is_bot_visible' => false]);

        $this->actingAs($this->admin)->postJson("/offers/{$offer->id}/story")->assertStatus(422)
            ->assertJsonPath('error', 'لا تنشر عرضاً لا يستطيع البوت الإجابة عنه: راجع شروط الظهور في «ما يراه البوت».');
        $this->actingAs($this->admin)->get("/offers/{$offer->id}/story/download")->assertStatus(422);
        $this->assertSame([], $this->shots);
    }

    public function test_the_story_routes_need_their_flag(): void
    {
        config(['features.env.offer_story_card' => false]);
        $offer = Offer::factory()->create();

        $this->actingAs($this->admin)->postJson("/offers/{$offer->id}/story")->assertNotFound();
        $this->actingAs($this->admin)->get("/offers/{$offer->id}/story/download")->assertNotFound();
    }

    public function test_the_chosen_code_is_printed_on_the_a4_card_only_when_active(): void
    {
        $offer = Offer::factory()->create();
        Storage::disk('public')->put('wa-qr/one.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        $link = WaQrLink::create(['offer_id' => $offer->id, 'channel' => 'print', 'code' => 'P1', 'prefilled_message' => 'x', 'status' => 'active', 'qr_png_path' => 'wa-qr/one.png']);

        $this->assertStringNotContainsString('امسح', OfferCardService::html($offer));
        $offer->forceFill(['card_qr_link_id' => $link->id])->save();
        $html = OfferCardService::html($offer->fresh());
        $this->assertStringContainsString('امسح', $html);
        $this->assertStringContainsString('src="data:image/png;base64,', $html);

        $link->update(['status' => 'deleted']);
        $this->assertStringNotContainsString('امسح', OfferCardService::html($offer->fresh()));
    }
}
