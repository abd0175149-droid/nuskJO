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
 * فتخرج رسالة واحدة: نصاً بخيارات مرقمة عبر البوابة (لا أزرار في واتساب ويب)، والمحفوظ interactive بمعرّفاته.
 * لا إعلان بلا العلم أو لمحادثة إدارة أو خارج المحادثات التجريبية.
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

    /** نصوص ما أُرسل إلى البوابة بالترتيب */
    private function sends(): array
    {
        return $this->sentTexts();
    }

    /** أنواع الصادر المحفوظة بالترتيب */
    private function outTypes(): array
    {
        return WaMessage::where('direction', 'out')->orderBy('id')->pluck('msg_type')->all();
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
        $sends = $this->gatewaySends();
        $this->assertCount(1, $sends);
        $this->assertSame('send-text', $sends[0]['path']);
        $this->assertStringStartsWith('هذه عروضنا المتاحة، اختر ما يناسبك.', $sends[0]['body']['text']);
        $this->assertStringContainsString("\n1. عمرة الربيع", $sends[0]['body']['text']);
        $this->assertArrayNotHasKey('_meta', $sends[0]['body']);

        $set = WaChoiceSet::sole();
        $row = WaMessage::where('direction', 'out')->sole();
        $this->assertSame('list', data_get($row->payload, 'interactive.type'));
        $this->assertSame('عمرة الربيع', data_get($row->payload, 'interactive.action.sections.0.rows.0.title'));
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
        $this->assertSame(trim($long), trim($sends[0]));
        $this->assertStringContainsString('اختر من الخيارات:', $sends[1]);
        $this->assertStringContainsString("1. احجز هذا\n2. سؤال آخر\n3. كلّم موظفاً", $sends[1]);
        $this->assertSame(['text', 'interactive'], $this->outTypes());
        $this->assertSame('booking_actions', WaChoiceSet::sole()->kind);
        $this->assertSame('wamid.OUT.2', WaChoiceSet::sole()->wamid);
    }

    public function test_the_tools_are_announced_only_with_the_flag_and_a_customer_chat(): void
    {
        $this->assertContains('show_offers_menu', $this->declared());
        $this->assertContains('present_choices', $this->declared());

        config(['features.env.interactive_choices' => false]);
        $this->assertNotContains('show_offers_menu', $this->declared());
        config(['features.env.interactive_choices' => true]);

        // لا نافذة 24 ساعة: محادثة قديمة تُعرض لها الخيارات
        $this->conv->forceFill(['last_inbound_at' => now()->subDays(3)])->save();
        $this->assertContains('show_offers_menu', $this->declared());

        config(['whatsapp.interactive_pilot_conversations' => [$this->conv->id + 100]]);
        $this->assertNotContains('present_choices', $this->declared(), 'خارج المحادثات التجريبية');
        config(['whatsapp.interactive_pilot_conversations' => [$this->conv->id]]);
        $this->assertContains('present_choices', $this->declared());

        WaAdminNumber::factory()->create(['phone' => $this->conv->phone]);
        $this->assertNotContains('show_offers_menu', $this->declared(), 'محادثة إدارة');
    }

    public function test_a_rejected_send_keeps_no_choice_set(): void
    {
        $this->fakeGraph(['nuskjo-openwa:2785/api/sessions/*/messages/send-text' => Http::response(['statusCode' => 400, 'message' => ['text too long']], 400)]);
        $this->fakeGemini(['call' => 'show_offers_menu', 'args' => []], 'اختر من عروضنا:');

        $this->assertFalse(BotEngine::reply($this->conv->fresh())['sent']);

        $this->assertCount(1, $this->sends());
        $this->assertSame(['failed'], WaMessage::where('direction', 'out')->pluck('status')->all());
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

        $this->assertCount(1, $this->sends());
        $this->assertSame(['text'], $this->outTypes());
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
