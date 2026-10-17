<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Offer;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaChoiceSet;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\TopicRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * أداتا القوائم والأزرار (E36، العلم interactive_choices): الأداة تجهّز ولا ترسل، ونص رد النموذج يصير body الرسالة
 * التفاعلية فتخرج رسالة واحدة. لا إعلان بلا العلم أو قرب انتهاء النافذة أو لمحادثة إدارة أو خارج المحادثات التجريبية.
 */
class InteractiveToolsTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel(['tools_config' => ['show_offers_menu' => true, 'present_choices' => true]]);
        config(['features.env.interactive_choices' => true]);
        $this->conv = WaConversation::factory()->windowOpen()->create(['phone' => '16505551234', 'wa_phone' => '16505551234']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'ما هي عروضكم؟']);
        $this->offer = Offer::factory()->create(['title' => 'عمرة الربيع']);
    }

    /** @return list<Request> */
    private function sends(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), '/messages'))->values()->all();
    }

    private function declared(?WaConversation $conv = null): array
    {
        return array_column(BotTools::declarations(WaBotSetting::current(), ($conv ?? $this->conv)->fresh()), 'name');
    }

    public function test_the_menu_and_the_model_text_leave_as_one_interactive_message(): void
    {
        $this->fakeGraph();
        $this->fakeGemini(['call' => 'show_offers_menu', 'args' => []], 'هذه عروضنا المتاحة، اختر ما يناسبك.');

        $res = BotEngine::reply($this->conv->fresh());

        $this->assertTrue($res['sent'], json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $this->sends());
        $req = $this->sends()[0];
        $this->assertSame(['interactive', 'list'], [$req['type'], $req['interactive']['type']]);
        $this->assertSame('هذه عروضنا المتاحة، اختر ما يناسبك.', $req['interactive']['body']['text']);
        $this->assertSame('عمرة الربيع', $req['interactive']['action']['sections'][0]['rows'][0]['title']);
        $this->assertArrayNotHasKey('_meta', $req->data());

        $set = WaChoiceSet::sole();
        $row = WaMessage::where('direction', 'out')->sole();
        $this->assertSame(['offers_menu', 'wamid.OUT.1', $row->id], [$set->kind, $set->wamid, (int) $set->message_id]);
        $this->assertSame($set->id, (int) data_get($row->payload, '_meta.choice_set_id'));
        $this->assertSame("هذه عروضنا المتاحة، اختر ما يناسبك.\n[قائمة: عمرة الربيع]", $row->body);
        $this->assertSame('interactive', $row->msg_type);
    }

    public function test_a_text_longer_than_the_button_limit_goes_first_then_the_buttons(): void
    {
        $this->fakeGraph();
        $long = str_repeat('تفاصيل العرض واضحة ', 60);
        $this->fakeGemini(['call' => 'present_choices', 'args' => ['kind' => 'booking_actions', 'offer_id' => $this->offer->id]], $long);

        $this->assertTrue(BotEngine::reply($this->conv->fresh())['sent']);

        $sends = $this->sends();
        $this->assertCount(2, $sends);
        $this->assertSame('text', $sends[0]['type']);
        $this->assertSame(['button', 'اختر من الخيارات:'], [$sends[1]['interactive']['type'], $sends[1]['interactive']['body']['text']]);
        $this->assertSame(['احجز هذا', 'سؤال آخر', 'كلّم موظفاً'], array_column(array_column($sends[1]['interactive']['action']['buttons'], 'reply'), 'title'));
        $this->assertSame('booking_actions', WaChoiceSet::sole()->kind);
        $this->assertSame('wamid.OUT.2', WaChoiceSet::sole()->wamid);
    }

    public function test_the_tools_are_announced_only_with_the_flag_a_long_enough_window_and_a_customer_chat(): void
    {
        $this->assertContains('show_offers_menu', $this->declared());
        $this->assertContains('present_choices', $this->declared());

        config(['features.env.interactive_choices' => false]);
        $this->assertNotContains('show_offers_menu', $this->declared());
        config(['features.env.interactive_choices' => true]);

        $this->conv->forceFill(['last_inbound_at' => now()->subDay()->addMinutes(9)])->save();
        $this->assertNotContains('show_offers_menu', $this->declared(), 'أقل من 10 دقائق من النافذة');
        $this->conv->forceFill(['last_inbound_at' => now()->subDay()->addMinutes(11)])->save();
        $this->assertContains('show_offers_menu', $this->declared());

        config(['whatsapp.interactive_pilot_conversations' => [$this->conv->id + 100]]);
        $this->assertNotContains('present_choices', $this->declared(), 'خارج المحادثات التجريبية');
        config(['whatsapp.interactive_pilot_conversations' => [$this->conv->id]]);
        $this->assertContains('present_choices', $this->declared());

        WaAdminNumber::factory()->create(['phone' => $this->conv->phone]);
        $this->assertNotContains('show_offers_menu', $this->declared(), 'محادثة إدارة');
    }

    public function test_a_format_rejection_sends_the_text_alone_and_keeps_no_choice_set(): void
    {
        $n = 0;
        $this->fakeGraph(['graph.facebook.com/*' => function () use (&$n) {
            $n++;

            return $n === 1
                ? Http::response(['error' => ['code' => 131009, 'message' => 'Parameter value is not valid', 'fbtrace_id' => 'AxTest']], 400)
                : Http::response(['messages' => [['id' => 'wamid.OUT.TEXT']]]);
        }]);
        $this->fakeGemini(['call' => 'show_offers_menu', 'args' => []], 'اختر من عروضنا:');

        $this->assertTrue(BotEngine::reply($this->conv->fresh())['sent']);

        $this->assertSame(['interactive', 'text'], array_map(fn ($r) => $r['type'], $this->sends()));
        $this->assertSame('اختر من عروضنا:', $this->sends()[1]['text']['body']);
        $this->assertSame(0, WaChoiceSet::count());
    }

    public function test_a_staff_reply_during_generation_drops_the_whole_reply(): void
    {
        $this->fakeGraph();
        $calls = 0;
        $conv = $this->conv;
        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$calls, $conv) {
            $calls++;
            if ($calls === 2) {
                WaMessage::create(['conversation_id' => $conv->id, 'direction' => 'out', 'source' => 'staff', 'msg_type' => 'text',
                    'body' => 'أهلاً، معك موظف', 'status' => 'sent', 'staff_id' => User::factory()->create()->id]);
            }

            return Http::response($this->geminiResponse($calls === 1 ? ['call' => 'show_offers_menu', 'args' => []] : 'عروضنا:'));
        }]);

        $res = BotEngine::reply($this->conv->fresh());

        $this->assertFalse($res['sent']);
        $this->assertSame([], $this->sends());
        $this->assertSame(0, WaChoiceSet::count());
    }

    public function test_a_handoff_in_the_same_run_drops_the_choices(): void
    {
        $this->fakeGraph();
        $this->fakeGemini(['call' => 'show_offers_menu', 'args' => []],
            ['call' => 'request_quote', 'args' => ['type' => 'flight', 'details' => 'تذكرة إلى جدة']], 'وصل طلبك لفريقنا.');

        $this->assertTrue(BotEngine::reply($this->conv->fresh())['sent']);

        $this->assertSame(['text'], array_map(fn ($r) => $r['type'], $this->sends()));
        $this->assertSame(0, WaChoiceSet::count());
    }

    public function test_the_tool_only_stages_and_reports_titles_without_ids_or_prices(): void
    {
        $this->fakeGraph();
        $this->fakeGemini(['call' => 'show_offers_menu', 'args' => ['category' => 'umrah']], 'اختر:');

        BotEngine::reply($this->conv->fresh());

        $second = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), 'generativelanguage'))->values()[1];
        $toolResult = json_encode($second->data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('"staged":true', $toolResult);
        $this->assertStringContainsString('عمرة الربيع', $toolResult);
        $this->assertStringNotContainsString('ch:', $toolResult);
        $this->assertStringNotContainsString('220', $toolResult);
        $this->assertContains('umrah', array_column(TopicRouter::topicsOf($this->conv->fresh()), 'key'));
    }

    public function test_the_rules_tell_the_model_how_to_read_a_verified_choice(): void
    {
        $this->assertStringContainsString('show_offers_menu', BotEngine::SYSTEM_RULES);
        $this->assertStringContainsString('[اختار من القائمة: …]', BotEngine::SYSTEM_RULES);
    }
}
