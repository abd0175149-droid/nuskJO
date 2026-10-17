<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotAnalytics;
use App\Services\WhatsApp\BotEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * البوت مع دفتر الإرسال (E19): إعادة الرد للوارد نفسه (المُصالِح أو وظيفة مكررة) لا تُخرج رسالة ثانية وتتحرك العلامة،
 * والرد غير المؤكد يحوّل المحادثة لموظف بلا إعادة، وغير المسلَّم خارج سياق النموذج والتحليلات.
 */
class BotSendLedgerTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.send_ledger' => true]);
        $this->conv = WaConversation::factory()->windowOpen()->create(['bot_enabled' => true]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'كم سعر العمرة؟']);
    }

    private function fakeModel(array $turns, array $graph = []): void
    {
        $n = 0;
        $this->fakeGraph($graph);
        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$n, $turns) {
            return Http::response($this->geminiResponse($turns[min($n++, count($turns) - 1)]));
        }]);
    }

    public function test_a_second_run_for_the_same_messages_replays_and_moves_the_watermark(): void
    {
        $this->fakeModel(['العمرة تبدأ من 350', 'صياغة أخرى للرد نفسه']);

        BotEngine::reply($this->conv);
        // الوظيفة ماتت بعد الإرسال وقبل تحريك العلامة، فأعادها المُصالِح
        WaConversation::whereKey($this->conv->id)->update(['bot_answered_in_id' => null]);
        $second = BotEngine::reply($this->conv->fresh());

        $this->assertTrue($second['sent']);
        $this->assertSame(1, WaMessage::where('direction', 'out')->count());
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'graph.facebook.com'))->count());
        $this->assertNotNull($this->conv->fresh()->bot_answered_in_id);
    }

    public function test_an_unknown_reply_goes_to_staff_without_a_resend(): void
    {
        $this->fakeModel(['العمرة تبدأ من 350'], ['graph.facebook.com/*' => Http::failedConnection('cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received')]);

        $result = BotEngine::reply($this->conv);

        $this->assertFalse($result['sent']);
        $conv = $this->conv->fresh();
        $this->assertSame([true, 'send_unknown'], [$conv->needs_attention, $conv->attention_reason]);
        $this->assertSame('unknown', WaMessage::where('direction', 'out')->sole()->status);
        $this->assertContains('send_unknown', WaBotUsage::where('source', 'gate')->sole()->tools_used);
    }

    public function test_undelivered_outbound_stays_out_of_the_model_context(): void
    {
        foreach (['queued' => 'نص قيد الإرسال', 'failed' => 'نص فشل', 'unknown' => 'نص غير مؤكد', 'sent' => 'نص وصل'] as $status => $body) {
            WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'status' => $status, 'body' => $body]);
        }
        // الوارد الأول خُدم (العلامة عليه)، فالصادر بعده جزء من السياق، والوارد الجديد ينتظر
        $this->conv->forceFill(['bot_answered_in_id' => WaMessage::where('direction', 'in')->min('id')])->save();
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'وكم للأطفال؟']);
        $this->fakeModel(['ردّ']);

        BotEngine::reply($this->conv);

        $sentToModel = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'generativelanguage'))
            ->map(fn ($p) => json_encode($p[0]->data(), JSON_UNESCAPED_UNICODE))->implode(' ');
        $this->assertStringContainsString('نص وصل', $sentToModel);
        foreach (['نص قيد الإرسال', 'نص فشل', 'نص غير مؤكد'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $sentToModel);
        }
    }

    public function test_analytics_count_undelivered_apart(): void
    {
        foreach (['failed', 'unknown', 'unknown', 'sent'] as $status) {
            WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'status' => $status, 'source' => 'bot']);
        }

        $metrics = collect(BotAnalytics::quality()['metrics'])->keyBy('key');

        $this->assertSame(2, $metrics['send_unknown']['value']);
        $this->assertSame(1, $metrics['send_failed']['value']);
    }
}
