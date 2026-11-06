<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * بوابة الإرسال في E33 (على OpenWA منذ 2026-11-06): الاقتباس الصادر من المحادثة نفسها وحدها (quotedMessageId)، وإعادة
 * واحدة بلا اقتباس حين ترفضه البوابة صراحةً (QUOTE_NOT_FOUND، لا بعد مهلة ولا 5xx)، وmeta لا يصل البوابة، ومعاينة الرابط
 * (linkPreview) يقررها dispatch: للموظف وحده بالعلم والسياسة.
 */
class ChannelContextTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    private WaMessage $in;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->conv = WaConversation::factory()->create();
        $this->in = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.IN.1', 'body' => 'كم سعر الغرفة الثلاثية؟']);
    }

    public function test_reply_to_adds_the_quote_and_records_the_quoted_wamid(): void
    {
        $this->fakeGateway();

        $res = WhatsAppChannel::sendText($this->conv, 'نعم، متاحة', 'bot', sendKey: SendKey::reply($this->conv, null, 'text'), opts: ['reply_to' => $this->in]);

        $this->assertTrue($res['ok']);
        $this->assertSame('wamid.IN.1', $this->gatewaySends()[0]['body']['quotedMessageId']);
        $this->assertSame('wamid.IN.1', WaMessage::where('direction', 'out')->sole()->reply_to_wamid);
    }

    public function test_a_target_from_another_conversation_without_a_wamid_or_a_reaction_is_refused_without_http(): void
    {
        $this->fakeGateway();
        $other = WaMessage::factory()->inbound()->create(['wamid' => 'wamid.OTHER.1']);
        $unsent = WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'wamid' => null, 'status' => 'failed']);
        $reaction = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'reaction', 'body' => '👍']);

        foreach ([$other, $unsent, $reaction] as $target) {
            $res = WhatsAppChannel::sendText($this->conv, 'رد', 'bot', sendKey: 'reply:x:'.$target->id, opts: ['reply_to' => $target]);
            $this->assertSame([false, 'BAD_REPLY_TARGET'], [$res['ok'], $res['code']]);
        }
        Http::assertNothingSent();
        $this->assertSame([$unsent->id], WaMessage::where('direction', 'out')->pluck('id')->all(), 'لا صف جديد');
    }

    public function test_a_rejected_quote_is_resent_once_without_it_on_the_same_row(): void
    {
        $this->fakeGateway(['*/messages/send-text' => Http::sequence()
            ->push(['statusCode' => 400, 'message' => 'Quoted message not found on the device'], 400)
            ->push(['messageId' => 'wamid.OUT.OK'], 201)]);

        $res = WhatsAppChannel::sendText($this->conv, 'نعم، متاحة', 'bot', sendKey: SendKey::reply($this->conv, null, 'text'), opts: ['reply_to' => $this->in]);

        $this->assertTrue($res['ok']);
        $sends = $this->gatewaySends();
        $this->assertCount(2, $sends);
        $this->assertArrayHasKey('quotedMessageId', $sends[0]['body']);
        $this->assertArrayNotHasKey('quotedMessageId', $sends[1]['body']);
        $row = WaMessage::where('direction', 'out')->sole();
        $this->assertSame(['sent', 'wamid.OUT.OK', true], [$row->status, $row->wamid, data_get($row->payload, '_context_dropped')]);
        $this->assertArrayNotHasKey('context', (array) $row->payload);
        $this->assertNull($row->reply_to_wamid);
    }

    public function test_no_resend_after_a_timeout_or_an_engine_5xx(): void
    {
        $this->fakeGateway(['*/messages/send-text' => Http::sequence()
            ->push('', 502)
            ->pushFailedConnection('cURL error 28: Operation timed out after 90001 milliseconds with 0 bytes received')]);

        $first = WhatsAppChannel::sendText($this->conv, 'نعم', 'bot', sendKey: 'reply:a', opts: ['reply_to' => $this->in]);
        $second = WhatsAppChannel::sendText($this->conv, 'نعم', 'bot', sendKey: 'reply:b', opts: ['reply_to' => $this->in]);

        $this->assertSame([true, true], [$first['unknown'] ?? false, $second['unknown'] ?? false]);
        $this->assertSame(['unknown', 'unknown'], WaMessage::where('direction', 'out')->orderBy('id')->pluck('status')->all());
        $this->assertCount(2, $this->gatewaySends(), 'محاولة واحدة لكل رسالة');
        $this->assertSame(0, WaMessage::where('direction', 'out')->get()->filter(fn ($m) => data_get($m->payload, '_context_dropped'))->count());
    }

    public function test_a_rejection_that_does_not_mention_the_quote_is_not_resent(): void
    {
        $this->fakeGateway(['*/messages/send-text' => Http::response(['statusCode' => 400, 'message' => 'text must be shorter than 4096 characters'], 400)]);

        $res = WhatsAppChannel::sendText($this->conv, 'نعم', 'bot', sendKey: 'reply:c', opts: ['reply_to' => $this->in]);

        $this->assertFalse($res['ok']);
        $this->assertCount(1, $this->gatewaySends());
    }

    public function test_meta_is_stored_with_the_row_and_never_sent(): void
    {
        $this->fakeGateway();

        WhatsAppChannel::sendText($this->conv, 'العرض', 'bot', sendKey: 'reply:d', opts: ['meta' => ['offer_id' => 7]]);

        $this->assertStringNotContainsString('offer_id', json_encode($this->gatewaySends()[0]['body']));
        $this->assertSame(['offer_id' => 7], WaMessage::where('direction', 'out')->sole()->payload['_meta']);
    }

    public function test_without_meta_the_row_payload_carries_no_private_keys(): void
    {
        $this->fakeGateway();

        WhatsAppChannel::sendText($this->conv, 'العرض', 'bot', sendKey: 'reply:e');

        $payload = WaMessage::where('direction', 'out')->sole()->payload;
        // _req_hash: بصمة الطلب في الدفتر (E19) وحدها
        $this->assertSame(['_req_hash'], array_values(array_filter(array_keys($payload), fn ($k) => str_starts_with((string) $k, '_'))));
        $this->assertSame('العرض', data_get($payload, 'text.body'));
        $this->assertSame('العرض', $this->gatewaySends()[0]['body']['text']);
    }

    public function test_link_preview_is_for_staff_only_with_the_flag_and_a_public_link(): void
    {
        $this->fakeGateway();
        config(['app.url' => 'https://nusk.example']);
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $send = fn (string $text, string $source) => WhatsAppChannel::sendText($this->conv, $text, $source, $source === 'staff' ? $staff->id : null,
            sendKey: $source === 'staff' ? 'staff:'.uniqid() : 'reply:'.uniqid(), opts: ['preview_url' => true]);

        // العلم مطفأ: false ولو طلبه الموظف
        $send('https://www.nusuk.sa', 'staff');
        config(['features.env.wa_link_preview' => true]);
        $send('https://www.nusuk.sa', 'staff');
        $send('https://nusk.example/storage/x.pdf', 'staff');
        $send('https://www.nusuk.sa', 'bot');
        $send('https://www.nusuk.sa', 'system');

        $this->assertSame([false, true, false, false, false], array_map(fn ($r) => $r['body']['linkPreview'], $this->gatewaySends()));
    }
}
