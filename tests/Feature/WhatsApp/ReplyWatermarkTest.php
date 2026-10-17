<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\User;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\ReplyRun;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * علامة الرد ورمز التشغيل:
 *  - رسالة تصل أثناء توليد رد سابق تنال ردها (كانت تضيع لأن رد A يُحفظ بعدها).
 *  - رد موظف أو إطفاء البوت أثناء التوليد يُسقط رد البوت، وتحويل البوت نفسه لا يُسقط تأكيده.
 */
class ReplyWatermarkTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    private WaMessage $first;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->conv = WaConversation::factory()->windowOpen()->create(['bot_enabled' => true]);
        $this->first = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'كم سعر العمرة؟']);
    }

    /** نموذج يرد بنصوص بالترتيب، وينفّذ $during مع أول نداء (حدث «أثناء التوليد») */
    private function fakeModel(array $turns, ?\Closure $during = null): void
    {
        $this->fakeGraph();
        $n = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$n, $turns, $during) {
            if ($n === 0 && $during) {
                $during();
            }

            return Http::response($this->geminiResponse($turns[min($n++, count($turns) - 1)]));
        }]);
    }

    private function botOutbound(): array
    {
        return WaMessage::where('direction', 'out')->where('source', 'bot')->orderBy('id')->pluck('body')->all();
    }

    #[Group('INV-15')]
    public function test_a_message_arriving_during_generation_gets_its_own_reply(): void
    {
        Queue::fake();
        $second = null;
        $this->fakeModel(['رد على الأولى', 'رد على الثانية'], function () use (&$second) {
            $second = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'وكم للأطفال؟']);
        });

        (new ProcessWhatsAppReply($this->conv->id, 'tok'))->handle();

        $this->assertSame(['رد على الأولى'], $this->botOutbound());
        $this->assertSame($this->first->id, $this->conv->fresh()->bot_answered_in_id);
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);   // إعادة احتياطية للوارد الجديد

        Queue::pushed(ProcessWhatsAppReply::class)->first()->handle();

        $this->assertSame(['رد على الأولى', 'رد على الثانية'], $this->botOutbound());
        $this->assertSame($second->id, $this->conv->fresh()->bot_answered_in_id);

        // السياق: الوارد الجديد دور أخير بعد رد البوت، لا قبله
        $lastRequest = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), 'generativelanguage'))->last();
        $contents = $lastRequest['contents'];
        $this->assertSame('user', end($contents)['role']);
        $this->assertStringContainsString('وكم للأطفال؟', end($contents)['parts'][0]['text']);
        $this->assertSame('model', $contents[count($contents) - 2]['role']);
    }

    public function test_nothing_is_sent_twice_for_the_same_message(): void
    {
        $this->fakeModel(['أهلاً بك، العروض متاحة']);

        BotEngine::reply($this->conv);
        BotEngine::reply($this->conv->fresh());

        $this->assertSame(['أهلاً بك، العروض متاحة'], $this->botOutbound());
    }

    public function test_a_staff_reply_during_generation_drops_the_bot_reply(): void
    {
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $this->fakeModel(['رد البوت'], function () use ($staff) {
            WaMessage::factory()->fromStaff($staff)->create(['conversation_id' => $this->conv->id, 'body' => 'أهلاً، معك الموظف']);
        });

        $result = BotEngine::reply($this->conv);

        $this->assertSame('stale_takeover', $result['reason']);
        $this->assertSame([], $this->botOutbound());
        $this->assertSame(['stale_takeover'], WaBotUsage::where('source', 'gate')->sole()->tools_used);
    }

    public function test_switching_the_bot_off_during_generation_drops_the_reply(): void
    {
        $this->fakeModel(['رد البوت'], fn () => WaConversation::whereKey($this->conv->id)->update(['bot_enabled' => false]));

        $this->assertSame('stale_disabled', BotEngine::reply($this->conv)['reason']);
        $this->assertSame([], $this->botOutbound());
        $this->assertNull($this->conv->fresh()->bot_answered_in_id, 'العلامة لا تتحرك لرد أُسقط');
    }

    public function test_the_bots_own_handoff_confirmation_is_not_dropped(): void
    {
        $this->fakeModel([['call' => 'handoff_to_human', 'args' => ['reason' => 'طلب موظفاً']], 'حوّلتك إلى أحد موظفينا']);

        $result = BotEngine::reply($this->conv);

        $this->assertTrue($result['sent']);
        $this->assertFalse($this->conv->fresh()->bot_enabled);
        $this->assertSame(['حوّلتك إلى أحد موظفينا'], $this->botOutbound());
    }

    public function test_a_failed_send_keeps_the_message_pending(): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'x', 'code' => 130429]], 400)]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse('رد'))]);

        $this->assertFalse(BotEngine::reply($this->conv)['sent']);

        $this->assertNull($this->conv->fresh()->bot_answered_in_id);
    }

    public function test_staff_reply_and_resolve_mark_everything_so_far_as_served(): void
    {
        $this->fakeGraph();
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'], 'desk')->create();

        WhatsAppChannel::sendText($this->conv, 'معك الموظف', 'staff', $staff->id);
        $this->assertSame($this->first->id, $this->conv->fresh()->bot_answered_in_id);

        $later = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id]);
        $this->actingAs($staff)->postJson("/api/whatsapp/conversations/{$this->conv->id}/resolve")->assertOk();
        $this->assertSame($later->id, $this->conv->fresh()->bot_answered_in_id);
    }

    public function test_the_old_gate_still_works_with_the_flag_off(): void
    {
        $this->artisan('whatsapp:reliability', ['flag' => 'watermark', 'state' => 'off']);
        $this->fakeModel(['رد']);
        WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id]);

        $this->assertSame('لا وارد بعد آخر رد', BotEngine::reply($this->conv)['reason']);
    }

    public function test_run_marks_escalation_only_for_its_own_handoff(): void
    {
        $run = ReplyRun::start($this->conv, 't');
        $this->assertNull($run->mayDeliver($this->conv));

        $this->conv->update(['bot_paused_until' => now()->addMinutes(30)]);
        $this->assertSame('stale_paused', $run->mayDeliver($this->conv));

        $run->escalated = true;
        $this->assertNull($run->mayDeliver($this->conv));
    }
}
