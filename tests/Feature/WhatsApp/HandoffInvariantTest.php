<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaAdminNumber;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\HandoffGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * INV-09: لا ردّ بعد التحويل ولا أثناء الإيقاف، ورقم الإدارة استثناء موثّق.
 * INV-10: الطلب الصريح لموظف يُحوَّل بالكود بلا نموذج، وزعم التحويل بلا أداة يُنفَّذ فعلاً.
 *
 * القناة والنموذج مهيّآن وGemini وGraph مزيّفان في كل حالة، والحالة الضابطة تثبت أن البوت يردّ
 * في هذا الإعداد. فـ assertNothingSent يعني أن البوت امتنع، لا أن الإعداد ناقص.
 */
class HandoffInvariantTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
    }

    private function inbound(WaConversation $conv, string $body): WaMessage
    {
        return WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => $body]);
    }

    private function isGemini(Request $r): bool
    {
        return str_contains($r->url(), 'generativelanguage.googleapis.com');
    }

    private function isGraphText(Request $r, ?string $body = null): bool
    {
        return str_contains($r->url(), 'graph.facebook.com')
            && $r['type'] === 'text'
            && ($body === null || $r['text']['body'] === $body);
    }

    private function toolsUsed(WaConversation $conv): array
    {
        return WaBotUsage::where('conversation_id', $conv->id)->sole()->tools_used;
    }

    // ==================== INV-09 ====================

    #[Group('INV-09')]
    public function test_control_active_conversation_gets_a_reply(): void
    {
        $this->fakeGraph();
        $this->fakeGemini('أهلاً بك، كيف أخدمك؟');
        $conv = WaConversation::factory()->windowOpen()->create();
        $this->inbound($conv, 'مرحبا');

        $this->assertTrue(BotEngine::reply($conv)['sent']);

        Http::assertSent(fn (Request $r) => $this->isGemini($r));
        Http::assertSent(fn (Request $r) => $this->isGraphText($r, 'أهلاً بك، كيف أخدمك؟'));
    }

    #[Group('INV-09')]
    public function test_handed_off_conversation_gets_no_reply(): void
    {
        $this->fakeGraph();
        $this->fakeGemini('ردّ لا يجوز أن يُرسل');
        $conv = WaConversation::factory()->windowOpen()->botOff()->create();
        $this->inbound($conv, 'مرحبا، في حدا؟');

        $result = BotEngine::reply($conv);

        $this->assertFalse($result['sent']);
        Http::assertNothingSent();
        $this->assertSame(0, $conv->messages()->where('direction', 'out')->count());
    }

    #[Group('INV-09')]
    public function test_paused_conversation_gets_no_reply(): void
    {
        $this->fakeGraph();
        $this->fakeGemini('ردّ لا يجوز أن يُرسل');
        $conv = WaConversation::factory()->windowOpen()->paused(30)->create();
        $this->inbound($conv, 'مرحبا');

        $result = BotEngine::reply($conv);

        $this->assertFalse($result['sent']);
        Http::assertNothingSent();
        $this->assertSame(0, $conv->messages()->where('direction', 'out')->count());
    }

    #[Group('INV-09')]
    public function test_after_handoff_tool_the_next_customer_message_gets_no_reply(): void
    {
        $this->fakeGraph();
        $this->fakeGemini(['call' => 'handoff_to_human', 'args' => ['reason' => 'شكوى']], 'سيتواصل معك أحد زملائنا قريباً.');
        $conv = WaConversation::factory()->windowOpen()->create();
        $this->inbound($conv, 'عندي مشكلة بالحجز');

        $this->assertTrue(BotEngine::reply($conv)['sent']);
        $this->assertFalse($conv->fresh()->bot_enabled);
        $sentBefore = Http::recorded()->count();

        $this->inbound($conv, 'وينكم؟');
        $result = BotEngine::reply($conv->fresh());

        $this->assertFalse($result['sent']);
        $this->assertSame($sentBefore, Http::recorded()->count(), 'لا نداء جديد للنموذج ولا لميتا بعد التحويل');
    }

    /** الاستثناء الموثّق: المساعد الإداري يخدم رقم الإدارة ولو كان البوت موقوفاً لهذه المحادثة */
    #[Group('INV-09')]
    public function test_admin_number_is_served_even_when_the_conversation_bot_is_off(): void
    {
        $this->fakeGraph();
        $this->fakeGemini('ملخّص اليوم جاهز.');
        $conv = WaConversation::factory()->windowOpen()->botOff()->paused(30)->create();
        WaAdminNumber::factory()->create(['phone' => $conv->phone]);
        $this->inbound($conv, 'ملخص اليوم');

        $result = BotEngine::reply($conv);

        $this->assertTrue($result['sent']);
        Http::assertSent(fn (Request $r) => $this->isGemini($r));
        Http::assertSent(fn (Request $r) => $this->isGraphText($r, 'ملخّص اليوم جاهز.'));
        $this->assertSame('admin', WaBotUsage::where('conversation_id', $conv->id)->sole()->source);
    }

    // ==================== INV-10 ====================

    #[Group('INV-10')]
    public function test_explicit_request_for_a_human_hands_off_without_calling_the_model(): void
    {
        $this->fakeGraph();
        $this->fakeGemini('ردّ لا يجوز أن يُطلب');
        $conv = WaConversation::factory()->windowOpen()->create();
        $this->inbound($conv, 'بدي احكي مع موظف');

        $result = BotEngine::reply($conv);

        $this->assertTrue($result['sent']);
        Http::assertNotSent(fn (Request $r) => $this->isGemini($r));
        Http::assertSent(fn (Request $r) => $this->isGraphText($r, HandoffGuard::confirmation(false)));

        $conv->refresh();
        $this->assertFalse($conv->bot_enabled);
        $this->assertTrue($conv->needs_attention);
        $this->assertSame(['handoff_guard'], $this->toolsUsed($conv));
    }

    #[Group('INV-10')]
    public function test_explicit_request_in_english_gets_the_english_confirmation(): void
    {
        $this->fakeGraph();
        $this->fakeGemini('should not be requested');
        $conv = WaConversation::factory()->windowOpen()->create();
        $this->inbound($conv, 'Can I talk to a human please?');

        BotEngine::reply($conv);

        Http::assertNotSent(fn (Request $r) => $this->isGemini($r));
        Http::assertSent(fn (Request $r) => $this->isGraphText($r, HandoffGuard::confirmation(true)));
        $this->assertFalse($conv->fresh()->bot_enabled);
    }

    #[Group('INV-10')]
    public function test_model_claiming_a_handoff_without_the_tool_really_hands_off(): void
    {
        $claim = 'تم تحويل محادثتك لأحد زملائنا وسيتواصل معك قريباً.';
        $this->fakeGraph();
        $this->fakeGemini($claim);
        $conv = WaConversation::factory()->windowOpen()->create();
        $this->inbound($conv, 'عندي مشكلة بالحجز');

        $result = BotEngine::reply($conv);

        $this->assertTrue($result['sent']);
        Http::assertSentCount(2); // نداء واحد للنموذج وردّ واحد لميتا
        Http::assertSent(fn (Request $r) => $this->isGraphText($r, $claim));

        $conv->refresh();
        $this->assertFalse($conv->bot_enabled);
        $this->assertTrue($conv->needs_attention);
        $this->assertContains('handoff_guard', $this->toolsUsed($conv));
    }

    #[Group('INV-10')]
    public function test_control_ordinary_reply_does_not_hand_off(): void
    {
        $this->fakeGraph();
        $this->fakeGemini('أهلاً بك، عروض العمرة متوفرة هذا الشهر.');
        $conv = WaConversation::factory()->windowOpen()->create();
        $this->inbound($conv, 'عندي سؤال عن العمرة');

        BotEngine::reply($conv);

        $conv->refresh();
        $this->assertTrue($conv->bot_enabled);
        $this->assertFalse($conv->needs_attention);
        $this->assertNotContains('handoff_guard', $this->toolsUsed($conv));
    }

    #[Group('INV-10')]
    public function test_claim_backed_by_the_handoff_tool_is_not_escalated_twice(): void
    {
        $this->fakeGraph();
        $this->fakeGemini(['call' => 'handoff_to_human', 'args' => ['reason' => 'شكوى']], 'سيتواصل معك أحد زملائنا قريباً.');
        $conv = WaConversation::factory()->windowOpen()->create();
        $this->inbound($conv, 'عندي مشكلة بالحجز');

        BotEngine::reply($conv);

        $tools = $this->toolsUsed($conv);
        $this->assertContains('ok:handoff_to_human', $tools, 'tools_used بصيغة الحالة:الاسم (E15.3)');
        $this->assertNotContains('handoff_guard', $tools);
        $this->assertFalse($conv->fresh()->bot_enabled);
    }
}
