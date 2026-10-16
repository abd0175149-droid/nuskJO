<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\User;
use App\Models\WaQrLink;
use App\Services\WhatsApp\OfferQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * رموز QR الرسمية (E38.3/E38.4): الرسالة المعبأة من العنوان والمرجع وحدهما بحد 140، والنداء الرسمي بشكله، والحفظ كما
 * أعادته ميتا، وتنزيل الصورة فوراً، وحد الـ2000، والأقفال والعلم بلا أي نداء، والتصنيف عند الفشل بلا تسجيل الرمز.
 */
class OfferQrServiceTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const IMAGE = 'https://scontent.xx.fbcdn.net/v/t39.8562-6/qr.png?oh=sig';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.wa_qr_links' => true]);
        Storage::fake('public');
        Storage::fake('local');
        $this->admin = User::factory()->admin()->create();
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(64, 64);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** ميتا تعيد الرسالة كما أُرسلت (أو $returned إن حُدد)، والصورة على fbcdn */
    private function fakeMeta(?string $returned = null): void
    {
        Http::fake([
            'graph.facebook.com/*' => fn (Request $r) => $r->method() === 'DELETE' ? Http::response(['success' => true]) : Http::response([
                'code' => 'ZPBUTL6HCLHLJ1',
                'prefilled_message' => $returned ?? $r->data()['prefilled_message'],
                'deep_link_url' => 'https://wa.me/message/ZPBUTL6HCLHLJ1',
                'qr_image_url' => self::IMAGE,
            ]),
            'scontent.xx.fbcdn.net/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
    }

    public function test_the_prefilled_text_holds_the_title_and_the_reference_only_within_140(): void
    {
        $offer = Offer::factory()->create(['title' => str_repeat('عمرة رجب الميسّرة ', 12)]);
        $link = WaQrLink::create(['offer_id' => $offer->id, 'channel' => 'print', 'prefilled_message' => '', 'status' => 'pending']);

        $text = OfferQrService::prefilled($offer, $link);

        $this->assertLessThanOrEqual(140, mb_strlen($text));
        $this->assertStringEndsWith("[N-{$offer->id}/Q{$link->id}]", $text);
        $this->assertMatchesRegularExpression('/^مرحباً، أريد تفاصيل عرض «عمرة رجب[^»]*…» \[N-\d+\/Q\d+\]$/u', $text);

        $general = WaQrLink::create(['channel' => 'office', 'prefilled_message' => '', 'status' => 'pending']);
        $this->assertSame("مرحباً، أريد الاستفسار عن عروضكم [Q{$general->id}]", OfferQrService::prefilled(null, $general));
        $this->assertSame("Hello, I'd like details of offer [N-{$offer->id}/Q{$link->id}]", OfferQrService::prefilled($offer, $link, true));
    }

    public function test_create_calls_message_qrdls_and_keeps_what_meta_returned(): void
    {
        $this->fakeMeta();
        $offer = Offer::factory()->create(['title' => 'عمرة رجب']);

        $r = OfferQrService::create($offer, 'print', 'ملصق المعرض', $this->admin);

        $this->assertTrue($r['ok']);
        $link = $r['link']->fresh();
        Http::assertSent(fn (Request $q) => $q->method() === 'POST'
            && str_ends_with((string) parse_url($q->url(), PHP_URL_PATH), '/'.self::TEST_PHONE_NUMBER_ID.'/message_qrdls')
            && $q->data() === ['prefilled_message' => "مرحباً، أريد تفاصيل عرض «عمرة رجب» [N-{$offer->id}/Q{$link->id}]", 'generate_qr_image' => 'PNG']);
        $this->assertSame(['active', 'ZPBUTL6HCLHLJ1', 'https://wa.me/message/ZPBUTL6HCLHLJ1', true],
            [$link->status, $link->code, $link->deep_link_url, $link->token_ok]);
        $this->assertStringStartsWith('wa-qr/', $link->qr_png_path);
        Storage::disk('public')->assertExists($link->qr_png_path);
        $audit = AuditLog::where('action', 'wa.qr_create')->sole();
        $this->assertSame([$offer->id, 'print'], [$audit->changes['offer_id'], $audit->changes['channel']]);
    }

    public function test_a_reference_dropped_by_meta_marks_the_link(): void
    {
        $this->fakeMeta('مرحباً، أريد تفاصيل عرض «عمرة رجب»');

        $r = OfferQrService::create(Offer::factory()->create(['title' => 'عمرة رجب']), 'social', null, $this->admin);

        $this->assertFalse($r['link']->fresh()->token_ok);
    }

    public function test_the_two_thousand_limit_refuses_without_a_call_and_warns_from_1800(): void
    {
        $this->fakeMeta();
        $offer = Offer::factory()->create();
        $rows = fn (int $n, int $from) => collect(range($from, $from + $n - 1))->map(fn ($i) => [
            'channel' => 'print', 'code' => 'C'.$i, 'prefilled_message' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ])->chunk(400)->each(fn ($c) => WaQrLink::insert($c->all()));

        $rows(1799, 1);
        $r = OfferQrService::create($offer, 'print', null, $this->admin);
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('1800 من 2000', $r['warning']);

        $rows(200, 5000);
        Http::fake();
        $calls = count(Http::recorded());
        $r = OfferQrService::create($offer, 'print', null, $this->admin);
        $this->assertSame([false, 'QR_LIMIT'], [$r['ok'], $r['code']]);
        $this->assertSame($calls, count(Http::recorded()));
    }

    public function test_nothing_is_sent_while_suspended_flagged_off_or_for_a_hidden_offer(): void
    {
        Http::fake();
        $offer = Offer::factory()->create();

        $this->configureChannel(['wa_suspended' => true]);
        $this->assertSame('SENDING_SUSPENDED', OfferQrService::create($offer, 'print', null, $this->admin)['code']);

        $this->configureChannel();
        config(['features.env.wa_qr_links' => false]);
        $this->assertSame('QR_DISABLED', OfferQrService::create($offer, 'print', null, $this->admin)['code']);

        config(['features.env.wa_qr_links' => true]);
        $hidden = Offer::factory()->create(['is_bot_visible' => false]);
        $this->assertSame('OFFER_NOT_FOR_BOT', OfferQrService::create($hidden, 'print', null, $this->admin)['code']);

        Http::assertNothingSent();
        $this->assertSame(0, WaQrLink::count());
    }

    public function test_a_meta_failure_is_classified_and_the_token_never_logged(): void
    {
        $this->captureLogs();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => '(#100) Invalid parameter', 'type' => 'OAuthException',
            'code' => 100, 'fbtrace_id' => 'AbCdEf123']], 400)]);

        $r = OfferQrService::create(Offer::factory()->create(), 'print', null, $this->admin);

        $this->assertFalse($r['ok']);
        $link = WaQrLink::sole();
        $this->assertSame('failed', $link->status);
        $this->assertStringStartsWith('BAD_REQUEST', $link->last_error);
        $this->assertLogged('warning', 'wa.qrdl.failed');
        $this->assertStringNotContainsString('test-token', json_encode($this->logged, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('wa.me', json_encode($this->logged, JSON_UNESCAPED_UNICODE));
    }

    public function test_delete_removes_it_at_meta_and_here_and_drops_it_from_the_card(): void
    {
        $this->fakeMeta();
        $offer = Offer::factory()->create();
        $link = OfferQrService::create($offer, 'print', null, $this->admin)['link']->fresh();
        $offer->forceFill(['card_qr_link_id' => $link->id])->save();
        $png = $link->qr_png_path;

        $this->assertTrue(OfferQrService::delete($link, $this->admin)['ok']);

        Http::assertSent(fn (Request $q) => $q->method() === 'DELETE'
            && str_ends_with((string) parse_url($q->url(), PHP_URL_PATH), '/message_qrdls/ZPBUTL6HCLHLJ1'));
        $this->assertSame('deleted', $link->fresh()->status);
        $this->assertNull($offer->fresh()->card_qr_link_id);
        Storage::disk('public')->assertMissing($png);
        $this->assertSame('warn', AuditLog::where('action', 'wa.qr_delete')->sole()->severity);
    }

    public function test_the_weekly_sync_only_reads_and_counts(): void
    {
        WaQrLink::create(['channel' => 'print', 'code' => 'LOCAL1', 'prefilled_message' => 'x', 'status' => 'active']);
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            ['code' => 'LOCAL1', 'prefilled_message' => 'x', 'deep_link_url' => 'https://wa.me/message/LOCAL1'],
            ['code' => 'OTHER2', 'prefilled_message' => 'y', 'deep_link_url' => 'https://wa.me/message/OTHER2'],
        ]])]);

        $this->artisan('whatsapp:qr-sync', ['--json' => true])
            ->expectsOutput(json_encode(['meta' => 2, 'local' => 1, 'unknown_at_meta' => 1, 'missing_at_meta' => 0, 'truncated' => false]))
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $q) => $q->method() === 'GET');
        $this->assertSame(1, WaQrLink::count());
    }
}
