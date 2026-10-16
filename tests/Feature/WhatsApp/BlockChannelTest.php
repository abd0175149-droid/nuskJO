<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaBlock;
use App\Models\WaConversation;
use App\Services\WhatsApp\MetaErrorCatalog;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * نداءات block_users الرسمية (E31.2): الجسم بالرقم أو بالمعرّف وحده لا الاثنين، ولا نداء خارج النافذة أو أثناء
 * الإيقاف الطارئ، ورفض ميتا بنص عربي بلا إعادة تلقائية.
 */
class BlockChannelTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const BSUID = 'US.13491208655302741918';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/'.$name)), true);
    }

    private function customer(array $attrs = []): WaConversation
    {
        return WaConversation::factory()->windowOpen()->create($attrs + ['phone' => '16505551234', 'wa_phone' => '16505551234']);
    }

    private function isBlockCall(Request $r, string $method): bool
    {
        return $r->method() === $method && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/'.self::TEST_PHONE_NUMBER_ID.'/block_users');
    }

    public function test_blocking_by_phone_sends_exactly_the_official_body(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->fixture('block_users_ok.json'))]);

        $r = WhatsAppChannel::blockUsers($this->customer());

        $this->assertTrue($r['ok']);
        Http::assertSent(fn (Request $req) => $this->isBlockCall($req, 'POST')
            && $req->data() === ['messaging_product' => 'whatsapp', 'block_users' => [['user' => '+16505551234']]]);
        Http::assertSentCount(1);
    }

    public function test_a_customer_without_a_phone_is_blocked_by_user_id_alone_behind_its_flag(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'block_users' => ['added_users' => [['input' => self::BSUID, 'user_id' => self::BSUID]]]])]);
        $conv = $this->customer(['phone' => null, 'wa_phone' => null, 'wa_user_id' => self::BSUID]);

        // العلم مطفأ: الوثائق لم تؤكد قبول المعرّف، فلا نداء
        $off = WhatsAppChannel::blockUsers($conv);
        $this->assertSame([false, WhatsAppChannel::ERR_NO_RECIPIENT], [$off['ok'], $off['code']]);
        Http::assertNothingSent();

        config(['features.env.wa_block_bsuid' => true]);
        $this->assertTrue(WhatsAppChannel::blockUsers($conv)['ok']);
        Http::assertSent(fn (Request $req) => $this->isBlockCall($req, 'POST')
            && $req->data()['block_users'] === [['user_id' => self::BSUID]]);
    }

    public function test_the_phone_wins_and_both_are_never_sent_together(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->fixture('block_users_ok.json'))]);
        config(['features.env.wa_block_bsuid' => true]);

        WhatsAppChannel::blockUsers($this->customer(['wa_user_id' => self::BSUID]));

        Http::assertSent(fn (Request $req) => $req->data()['block_users'] === [['user' => '+16505551234']]);
    }

    public function test_a_parent_business_id_is_never_a_target(): void
    {
        Http::fake();
        config(['features.env.wa_block_bsuid' => true]);
        $conv = $this->customer(['phone' => null, 'wa_phone' => null, 'wa_user_id' => 'US.ENT.13491208655302741918']);

        $this->assertNull(WhatsAppChannel::blockTarget($conv));
        $this->assertSame(WhatsAppChannel::ERR_NO_RECIPIENT, WhatsAppChannel::blockUsers($conv)['code']);
        Http::assertNothingSent();
    }

    public function test_a_closed_window_is_refused_without_any_request(): void
    {
        Http::fake();

        $r = WhatsAppChannel::blockUsers(WaConversation::factory()->windowClosed()->create(['wa_phone' => '16505551234']));

        $this->assertSame(['BLOCK_WINDOW', 'لا يمكن الحظر إلا خلال 24 ساعة من آخر رسالة للعميل.'], [$r['code'], $r['error']]);
        Http::assertNothingSent();
    }

    public function test_a_suspended_channel_neither_blocks_nor_unblocks(): void
    {
        Http::fake();
        $this->configureChannel(['wa_suspended' => true]);
        $conv = $this->customer();

        $this->assertSame(WhatsAppChannel::ERR_SUSPENDED, WhatsAppChannel::blockUsers($conv)['code']);
        $this->assertSame(WhatsAppChannel::ERR_SUSPENDED, WhatsAppChannel::unblockUsers($conv)['code']);
        Http::assertNothingSent();
    }

    public static function refusals(): array
    {
        return [
            '131021 our own number' => [['error' => ['code' => 131021, 'message' => 'Recipient cannot be sender', 'fbtrace_id' => 'Ax1']], 400, 'SELF_RECIPIENT', 'لا يمكن حظر رقمنا.'],
            '139101 list full' => [['error' => ['code' => 139101, 'message' => 'Blocklist limit reached']], 400, 'BLOCKLIST_FULL', 'بلغت قائمة الحظر حدها (64 ألفاً).'],
            '139102' => [['error' => ['code' => 139102, 'message' => 'Block failed']], 400, 'BLOCK_FAILED', 'رفضت ميتا طلب الحظر.'],
            '130429 rate' => [['error' => ['code' => 130429, 'message' => 'Rate limit hit']], 429, 'RATE_LIMITED', 'ميتا تحدّ الطلبات الآن. أعد المحاولة بعد دقيقة.'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_meta_refusals_are_explained_in_arabic_and_never_retried(array $body, int $status, string $code, string $message): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($body, $status)]);

        $r = WhatsAppChannel::blockUsers($this->customer());

        $this->assertSame([false, $code, $message], [$r['ok'], $r['code'], $r['error']]);
        $this->assertSame($body['error']['code'], $r['meta_code']);
        Http::assertSentCount(1);
    }

    public function test_a_partial_failure_reports_the_code_of_the_failed_user(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->fixture('block_users_partial.json'), 400)]);

        $r = WhatsAppChannel::blockUsers($this->customer());

        // 131047 لهذا المستخدم أدق من 139100 العام
        $this->assertSame(['WINDOW_EXPIRED', 131047], [$r['code'], $r['meta_code']]);
        $this->assertSame(MetaErrorCatalog::BLOCK_MESSAGES[131047], $r['error']);
        Http::assertSentCount(1);
    }

    public function test_a_success_that_does_not_list_the_target_is_not_a_block(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'block_users' => ['added_users' => []]])]);

        $this->assertSame('BLOCK_UNCONFIRMED', WhatsAppChannel::blockUsers($this->customer())['code']);
    }

    public function test_unblocking_sends_a_delete_with_the_same_body_even_outside_the_window(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->fixture('block_users_removed.json'))]);
        $block = new WaBlock(['phone' => '16505551234']);

        $this->assertTrue(WhatsAppChannel::unblockUsers($block)['ok']);
        Http::assertSent(fn (Request $req) => $this->isBlockCall($req, 'DELETE')
            && $req->data() === ['messaging_product' => 'whatsapp', 'block_users' => [['user' => '+16505551234']]]
            && !str_contains($req->url(), 'block_users%5B'));
    }

    public function test_the_list_reads_pages_by_cursor(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->fixture('block_users_list.json'))]);

        $r = WhatsAppChannel::listBlocked('QVFIUmJlZm9yZQ');

        $this->assertSame([['phone' => '16505551234']], $r['data']);
        $this->assertSame('QVFIUmFmdGVy', $r['next_after']);
        Http::assertSent(fn (Request $req) => $req->method() === 'GET' && str_contains($req->url(), 'after=QVFIUmJlZm9yZQ') && str_contains($req->url(), 'limit=100'));
    }
}
