<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * بوابة الإرسال في E33: الاقتباس الصادر من المحادثة نفسها وحدها، وإعادة واحدة بلا context حين ترفضه ميتا صراحةً
 * (لا بعد مهلة ولا 5xx بلا جسم)، وmeta لا يصل ميتا، ومعاينة الرابط يقررها dispatch: للموظف وحده بالعلم والسياسة.
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
        $this->conv = WaConversation::factory()->windowOpen()->create();
        $this->in = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.IN.1', 'body' => 'كم سعر الغرفة الثلاثية؟']);
    }

    private function contextRejected(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/whatsapp/error_131009_context.json')), true);
    }

    private function sentRequests(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), '/messages'))->values()->all();
    }

    public function test_reply_to_adds_the_context_and_records_the_quoted_wamid(): void
    {
        $this->fakeGraph();

        $res = WhatsAppChannel::sendText($this->conv, 'نعم، متاحة', 'bot', sendKey: SendKey::reply($this->conv, null, 'text'), opts: ['reply_to' => $this->in]);

        $this->assertTrue($res['ok']);
        $this->assertSame(['message_id' => 'wamid.IN.1'], $this->sentRequests()[0]['context']);
        $this->assertSame('wamid.IN.1', WaMessage::where('direction', 'out')->sole()->reply_to_wamid);
    }

    public function test_a_target_from_another_conversation_without_a_wamid_or_a_reaction_is_refused_without_http(): void
    {
        $this->fakeGraph();
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

    public static function ledgerModes(): array
    {
        return ['legacy path' => [false], 'send ledger' => [true]];
    }

    #[DataProvider('ledgerModes')]
    public function test_a_rejected_context_is_resent_once_without_it_on_the_same_row(bool $ledger): void
    {
        config(['features.env.send_ledger' => $ledger]);
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push($this->contextRejected(), 400)
            ->push(['messages' => [['id' => 'wamid.OUT.OK']]])]);

        $res = WhatsAppChannel::sendText($this->conv, 'نعم، متاحة', 'bot', sendKey: SendKey::reply($this->conv, null, 'text'), opts: ['reply_to' => $this->in]);

        $this->assertTrue($res['ok']);
        $requests = $this->sentRequests();
        $this->assertCount(2, $requests);
        $this->assertArrayHasKey('context', $requests[0]->data());
        $this->assertArrayNotHasKey('context', $requests[1]->data());
        $row = WaMessage::where('direction', 'out')->sole();
        $this->assertSame(['sent', 'wamid.OUT.OK', true], [$row->status, $row->wamid, data_get($row->payload, '_context_dropped')]);
        $this->assertArrayNotHasKey('context', (array) $row->payload);
    }

    public function test_no_resend_after_a_timeout_or_a_bodyless_5xx(): void
    {
        config(['features.env.send_ledger' => true]);
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push('', 502)
            ->pushFailedConnection('cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received')]);

        $first = WhatsAppChannel::sendText($this->conv, 'نعم', 'bot', sendKey: 'reply:a', opts: ['reply_to' => $this->in]);
        $second = WhatsAppChannel::sendText($this->conv, 'نعم', 'bot', sendKey: 'reply:b', opts: ['reply_to' => $this->in]);

        $this->assertSame(['unknown', 'unknown'], [$first['status'], $second['status']]);
        $this->assertCount(2, $this->sentRequests(), 'محاولة واحدة لكل رسالة');
        $this->assertSame(0, WaMessage::where('direction', 'out')->get()->filter(fn ($m) => data_get($m->payload, '_context_dropped'))->count());
    }

    public function test_a_131009_that_does_not_mention_the_context_is_not_resent(): void
    {
        $body = $this->contextRejected();
        $body['error']['error_data']['details'] = 'Parameter value is not valid.';
        Http::fake(['graph.facebook.com/*' => Http::response($body, 400)]);

        $res = WhatsAppChannel::sendText($this->conv, 'نعم', 'bot', sendKey: 'reply:c', opts: ['reply_to' => $this->in]);

        $this->assertFalse($res['ok']);
        $this->assertCount(1, $this->sentRequests());
    }

    public function test_meta_is_stored_with_the_row_and_never_sent(): void
    {
        $this->fakeGraph();

        WhatsAppChannel::sendText($this->conv, 'العرض', 'bot', sendKey: 'reply:d', opts: ['meta' => ['offer_id' => 7]]);

        $this->assertArrayNotHasKey('_meta', $this->sentRequests()[0]->data());
        $this->assertSame(['offer_id' => 7], WaMessage::where('direction', 'out')->sole()->payload['_meta']);
    }

    public function test_without_meta_the_row_payload_is_exactly_the_request(): void
    {
        $this->fakeGraph();

        WhatsAppChannel::sendText($this->conv, 'العرض', 'bot', sendKey: 'reply:e');

        $this->assertSame($this->sentRequests()[0]->data(), WaMessage::where('direction', 'out')->sole()->payload);
    }

    public function test_link_preview_is_for_staff_only_with_the_flag_and_a_public_link(): void
    {
        $this->fakeGraph();
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

        $this->assertSame([false, true, false, false, false], array_map(fn (Request $r) => $r['text']['preview_url'], $this->sentRequests()));
    }
}
