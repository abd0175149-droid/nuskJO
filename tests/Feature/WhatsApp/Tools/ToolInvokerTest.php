<?php

namespace Tests\Feature\WhatsApp\Tools;

use App\Models\Client;
use App\Models\Offer;
use App\Models\QuoteRequest;
use App\Models\WaBotSetting;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotAnalytics;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\Tools\ToolInvoker;
use App\Services\WhatsApp\Tools\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * المنفّذ (E15.3/E15.7): اسم غير معلن أو أداة مطفأة = denied بلا تنفيذ، والفاشلة failed لا تُسكت حارس التحويل،
 * والمدخلات العدائية تُطبَّع وتُقصّ (أو تُرفض في وضع الفرض)، والنتيجة تحت سقفها وحجمها مسجّل.
 */
class ToolInvokerTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->conv = WaConversation::factory()->windowOpen()->create();
    }

    private function ask(string $body): void
    {
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => $body]);
        BotEngine::reply($this->conv->fresh());
    }

    private function toolsUsed(): array
    {
        return WaBotUsage::where('conversation_id', $this->conv->id)->latest('id')->first()->tools_used;
    }

    private function quote(array $in): array
    {
        return ToolInvoker::run('request_quote', $in, $this->conv->fresh(), ['request_quote']);
    }

    public function test_a_tool_the_owner_turned_off_is_denied_even_if_the_model_calls_it(): void
    {
        WaBotSetting::current()->forceFill(['tools_config' => ['get_my_balance' => false]])->save();
        $this->fakeGraph();
        $this->fakeGemini(['call' => 'get_my_balance', 'args' => []], 'تفضل.');

        $this->ask('كم رصيدي؟');

        $this->assertContains('denied:get_my_balance', $this->toolsUsed());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage')
            && str_contains((string) json_encode(json_decode($r->body(), true), JSON_UNESCAPED_UNICODE), 'أداة غير متاحة'));
    }

    public function test_a_name_outside_this_rounds_declarations_never_runs(): void
    {
        $out = ToolInvoker::run('handoff_to_human', ['reason' => 'x'], $this->conv, ['get_offers']);

        $this->assertSame('denied', $out['status']);
        $this->assertTrue((bool) $this->conv->fresh()->bot_enabled, 'لم يُنفَّذ شيء');
        $this->assertSame('denied', ToolInvoker::run('drop_tables', [], $this->conv, ['drop_tables'])['status']);
    }

    public function test_a_failed_booking_is_recorded_as_failed_and_the_guard_still_hands_off(): void
    {
        $this->fakeGraph();
        $this->fakeGemini(['call' => 'confirm_booking', 'args' => ['offer_id' => 999999, 'details' => 'أريد الحجز']], 'تم، سيتواصل معك أحد موظفينا قريباً.');

        $this->ask('احجز لي العرض');

        $tools = $this->toolsUsed();
        $this->assertContains('failed:confirm_booking', $tools);
        $this->assertContains('handoff_guard', $tools, 'الفشل لا يُعدّ تحويلاً، فيعمل الحارس');
        $this->assertFalse((bool) $this->conv->fresh()->bot_enabled);
    }

    public function test_hostile_inputs_are_normalized_and_clamped_in_log_mode(): void
    {
        $this->fakeGraph();
        $client = Client::factory()->create();
        $this->conv->update(['client_id' => $client->id]);
        $victim = Client::factory()->create();

        $out = $this->quote([
            'type' => 'spaceship', 'details' => str_repeat('ب', 100_000), 'adults' => 1e9, 'children' => -5, 'infants' => '3',
            'route_from' => "عمّان\0\u{202E}", 'depart_date' => 'غداً', 'client_id' => $victim->id, 'phone' => '0790000000',
        ]);

        $this->assertSame('ok', $out['status']);
        $q = QuoteRequest::sole();
        $this->assertSame(['other', 2000, 99, 0, 3, 'عمّان', null, $client->id],
            [$q->type, mb_strlen($q->details), $q->pax_adults, $q->pax_children, $q->pax_infants, $q->route_from, $q->depart_date, $q->client_id]);
        $this->assertNotSame('0790000000', $q->phone);
    }

    public function test_enforce_mode_refuses_with_field_names_only(): void
    {
        config(['features.env.tool_validation_enforce' => true]);

        $out = $this->quote(['type' => 'flight', 'details' => str_repeat('x', 3000), 'adults' => 500]);

        $this->assertSame('failed', $out['status']);
        $this->assertEqualsCanonicalizing(['details', 'adults'], $out['output']['fields']);
        $this->assertSame(0, QuoteRequest::count());
    }

    public function test_results_are_capped_and_their_size_recorded(): void
    {
        $big = ['count' => 200, 'offers' => array_fill(0, 200, ['title' => str_repeat('عرض ', 60), 'id' => 1]), 'note' => 'ملاحظة'];
        $shaped = ToolResult::shape($big, 6000);
        $this->assertLessThanOrEqual(6000, ToolResult::bytes($shaped));
        $this->assertTrue($shaped['truncated']);
        $this->assertSame('ملاحظة', $shaped['note']);
        $this->assertSame(['ok' => true], ToolResult::shape([], 6000));

        Offer::factory()->ready()->count(3)->create();
        $this->fakeGraph();
        $this->fakeGemini(['call' => 'get_offers', 'args' => []], 'هذه عروضنا.');
        $this->ask('ما العروض؟');

        $usage = WaBotUsage::where('conversation_id', $this->conv->id)->sole();
        $this->assertContains('ok:get_offers', $usage->tools_used);
        $this->assertGreaterThan(0, $usage->tool_result_bytes);
    }

    public function test_analytics_count_denied_and_failed_calls_and_strip_the_prefix(): void
    {
        WaBotUsage::create(['conversation_id' => $this->conv->id, 'source' => 'live', 'model' => 'm', 'calls' => 1, 'prompt_tokens' => 1, 'output_tokens' => 1,
            'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'tools_used' => ['ok:get_offers', 'denied:get_my_balance', 'failed:confirm_booking'], 'leaked' => false,
            'latency_ms' => 1, 'created_at' => now(), 'tool_result_bytes' => 2048]);
        WaBotUsage::create(['conversation_id' => $this->conv->id, 'source' => 'live', 'model' => 'm', 'calls' => 1, 'prompt_tokens' => 1, 'output_tokens' => 1,
            'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'tools_used' => ['get_offers'], 'leaked' => false, 'latency_ms' => 1, 'created_at' => now()]);

        $q = BotAnalytics::quality();
        $metric = fn ($key) => collect($q['metrics'])->firstWhere('key', $key)['value'];

        $this->assertSame([1, 1, 2.0], [$metric('tools_denied'), $metric('tools_failed'), $metric('tool_bytes_avg')]);
        $this->assertSame(2, collect($q['tools'])->firstWhere('name', 'get_offers')['count'], 'الصيغتان معاً');
    }
}
