<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaBlock;
use App\Models\WaConversation;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الحظر على البوابة (E31، ومنذ 2026-11-06 /contacts/{jid}/block على رقم المحادثة): POST للحظر وDELETE للفك،
 * ولا نداء أثناء الإيقاف الطارئ أو والرقم غير جاهز، والرفض بنص عربي بلا إعادة تلقائية. لا نافذة 24 ساعة.
 */
class BlockChannelTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
    }

    private function customer(array $attrs = []): WaConversation
    {
        return WaConversation::factory()->windowOpen()->create($attrs + ['phone' => '16505551234', 'wa_phone' => '16505551234', 'channel_id' => $this->testChannel()->id]);
    }

    private function blockCalls(string $method): \Illuminate\Support\Collection
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => $r->method() === $method && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/block'));
    }

    public function test_blocking_posts_to_the_jid_of_the_conversation_on_its_number(): void
    {
        $this->fakeGateway();

        $r = WhatsAppChannel::blockUsers($this->customer());

        $this->assertTrue($r['ok']);
        $calls = $this->blockCalls('POST');
        $this->assertCount(1, $calls);
        $this->assertStringEndsWith('/sessions/sess-1/contacts/'.rawurlencode('16505551234@c.us').'/block', (string) parse_url($calls->first()->url(), PHP_URL_PATH));
    }

    public function test_a_stored_jid_wins_over_the_phone(): void
    {
        $this->fakeGateway();

        WhatsAppChannel::blockUsers($this->customer(['jid' => '123456789012345@lid']));

        $this->assertStringContainsString(rawurlencode('123456789012345@lid'), $this->blockCalls('POST')->first()->url());
        $this->assertSame(['jid' => '123456789012345@lid'], WhatsAppChannel::blockTarget($this->customer(['jid' => '123456789012345@lid', 'phone' => '16505550000', 'wa_phone' => null])));
    }

    public function test_there_is_no_window_and_a_closed_one_still_blocks(): void
    {
        $this->fakeGateway();

        $this->assertTrue(WhatsAppChannel::blockUsers($this->customer(['last_inbound_at' => now()->subDays(3)]))['ok']);
    }

    public function test_a_suspended_or_disconnected_number_neither_blocks_nor_unblocks(): void
    {
        $this->fakeGateway();
        $conv = $this->customer();

        $this->configureChannel(['wa_suspended' => true]);
        $this->assertSame(WhatsAppChannel::ERR_SUSPENDED, WhatsAppChannel::blockUsers($conv)['code']);
        $this->assertSame(WhatsAppChannel::ERR_SUSPENDED, WhatsAppChannel::unblockUsers($conv)['code']);

        $this->configureChannel(['wa_suspended' => false]);
        $this->testChannel()->forceFill(['status' => 'disconnected'])->save();
        $this->assertSame(WhatsAppChannel::ERR_NOT_READY, WhatsAppChannel::blockUsers($conv->fresh())['code']);

        $this->assertCount(0, $this->blockCalls('POST'));
        $this->assertCount(0, $this->blockCalls('DELETE'));
    }

    public function test_a_gateway_refusal_is_explained_in_arabic_and_never_retried(): void
    {
        $this->fakeGateway(['nuskjo-openwa:2785/api/sessions/*/contacts/*/block' => Http::response(['message' => 'bad'], 400)]);

        $r = WhatsAppChannel::blockUsers($this->customer());

        $this->assertFalse($r['ok']);
        $this->assertSame('GW_REJECTED', $r['code']);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $r['error']);
        $this->assertCount(1, $this->blockCalls('POST'));
    }

    public function test_unblocking_sends_a_delete_from_the_block_row_or_the_conversation(): void
    {
        $this->fakeGateway();
        $conv = $this->customer();
        $row = new WaBlock(['conversation_id' => $conv->id]);

        $this->assertTrue(WhatsAppChannel::unblockUsers($row)['ok']);
        $this->assertTrue(WhatsAppChannel::unblockUsers($conv)['ok']);
        $this->assertCount(2, $this->blockCalls('DELETE'));
        $this->assertSame(WhatsAppChannel::ERR_NO_RECIPIENT, WhatsAppChannel::unblockUsers(['conversation_id' => null])['code']);
    }

    public function test_the_list_reads_every_ready_number(): void
    {
        $second = $this->makeChannel();
        $this->makeChannel(['status' => 'disconnected']);
        $this->fakeGateway(['nuskjo-openwa:2785/api/sessions/sess-1/contacts/blocked' => Http::response(['data' => [['id' => '16505551234@c.us']]]),
            'nuskjo-openwa:2785/api/sessions/sess-2/contacts/blocked' => Http::response(['data' => ['447700900123@c.us']])]);

        $list = WhatsAppChannel::listBlocked();

        $this->assertTrue($list['ok']);
        $this->assertSame([
            ['phone' => '16505551234', 'jid' => '16505551234@c.us', 'channel_id' => $this->testChannel()->id],
            ['phone' => '447700900123', 'jid' => '447700900123@c.us', 'channel_id' => $second->id],
        ], $list['data']);
        $this->assertNull($list['next_after']);
    }
}
