<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaAdminAction;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaKnowledgeEntry;
use App\Models\WaMessage;
use App\Services\WhatsApp\AdminTools;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\ReplyRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * زرّا «✅ تأكيد» و«✖️ إلغاء» تحت معاينة المساعد الإداري (E36، العلم admin_confirm_buttons): كل معاينة بـ nonce، والنقرة
 * تُحل بالكود قبل النموذج بشرط الـ nonce والرقم وcontext.id والمدة والصلاحية. زر قديم لا يؤكد إجراءً أحدث، وعنوان الزر
 * لا يمر بمسار التأكيد النصي، والتأكيد بكتابة «تأكيد» يبقى يعمل.
 */
class AdminConfirmButtonsTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    private WaAdminNumber $admin;

    private const ADD = ['action' => 'add', 'title' => 'مواعيد الدوام', 'content' => 'من التاسعة صباحاً إلى الخامسة مساءً.'];

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.admin_confirm_buttons' => true]);
        $this->conv = WaConversation::factory()->windowOpen()->create();
        $this->admin = WaAdminNumber::factory()->create(['phone' => $this->conv->phone]);
        $this->say('أضف معلومة عن مواعيد الدوام');
        $this->fakeGraph();
    }

    private function say(string $body, array $payload = [], ?string $replyTo = null): WaMessage
    {
        return WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => $body, 'payload' => $payload]
            + ($replyTo ? ['reply_to_wamid' => $replyTo] : []));
    }

    private function stage(array $in = self::ADD): array
    {
        ReplyRun::bind(ReplyRun::start($this->conv, 't'.(++$this->n)));
        try {
            return AdminTools::execute('manage_knowledge', $in, $this->conv->fresh(), $this->admin->fresh());
        } finally {
            ReplyRun::clear();
        }
    }

    private function pending(): array
    {
        return Cache::get("wa:admin:pending:{$this->conv->id}");
    }

    /** نقرة الموظف على زر رسالة المعاينة (context.id = wamid رسالتها) */
    private function press(string $id, ?string $context, string $title = '✅ تأكيد'): void
    {
        $this->say($title, ['_button_id' => $id], $context);
    }

    private function log(): array
    {
        return WaAdminAction::where('conversation_id', $this->conv->id)->orderBy('id')->get()->map(fn ($a) => "{$a->tool}:{$a->action}:{$a->status}")->all();
    }

    private function lastText(): string
    {
        $r = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), '/messages'))->last();

        return (string) ($r['text']['body'] ?? $r['interactive']['body']['text'] ?? '');
    }

    public function test_the_preview_carries_two_buttons_bound_to_its_nonce(): void
    {
        $out = $this->stage();

        $this->assertTrue($out['preview_sent']);
        $req = collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn (Request $r) => str_contains($r->url(), '/messages'));
        $nonce = $this->pending()['nonce'];
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', $nonce);
        $this->assertSame('button', $req['interactive']['type']);
        $this->assertStringContainsString('مواعيد الدوام', $req['interactive']['body']['text']);
        $this->assertSame([["adm:{$nonce}:y", '✅ تأكيد'], ["adm:{$nonce}:n", '✖️ إلغاء']],
            array_map(fn ($b) => [$b['reply']['id'], $b['reply']['title']], $req['interactive']['action']['buttons']));
        $this->assertSame('wamid.OUT.1', $this->pending()['wamid']);
        $this->assertSame(0, WaKnowledgeEntry::count());
    }

    public function test_pressing_confirm_executes_the_stored_action_without_the_model(): void
    {
        $this->stage();
        ['nonce' => $nonce, 'wamid' => $wamid] = $this->pending();
        $this->press("adm:{$nonce}:y", $wamid);

        $res = BotEngine::reply($this->conv->fresh());

        $this->assertTrue($res['sent']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
        $this->assertSame(self::ADD['content'], WaKnowledgeEntry::sole()->content);
        $this->assertSame(['manage_knowledge:add:preview', 'manage_knowledge:add:done'], $this->log());
        $this->assertStringStartsWith('تم: أُضيفت المعلومة', $this->lastText());
        $this->assertNull(Cache::get("wa:admin:pending:{$this->conv->id}"));
        $this->assertSame('system', WaMessage::where('direction', 'out')->orderByDesc('id')->first()->source);
    }

    public function test_an_old_button_does_not_confirm_a_newer_preview(): void
    {
        $this->stage();
        ['nonce' => $old, 'wamid' => $oldWamid] = $this->pending();
        $this->say('لا، أضف معلومة أخرى بدلها');
        $this->stage(['action' => 'add', 'title' => 'العنوان', 'content' => 'شارع المدينة المنورة، عمّان.']);
        $newer = $this->pending();
        $this->assertNotSame($old, $newer['nonce']);

        $this->press("adm:{$old}:y", $oldWamid);
        BotEngine::reply($this->conv->fresh());

        $this->assertSame(0, WaKnowledgeEntry::count());
        $this->assertSame($newer, $this->pending(), 'الإجراء المعلّق الأحدث لا يُمس');
        $this->assertSame('هذا الزر لإجراء سابق انتهى أو تغيّر — اطلب الإجراء من جديد.', $this->lastText());
        $this->assertSame('admin_confirm:confirm:denied', last($this->log()));
    }

    public function test_a_button_from_another_message_or_after_ten_minutes_is_refused(): void
    {
        $this->stage();
        ['nonce' => $nonce, 'wamid' => $wamid] = $this->pending();
        $this->press("adm:{$nonce}:y", 'wamid.OTHER');
        BotEngine::reply($this->conv->fresh());
        $this->assertSame(0, WaKnowledgeEntry::count());

        // مدخل بقي في الكاش بعد عشر دقائق (ساعة متأخرة مثلاً): المدة من وقت المعاينة نفسها
        $kept = $this->pending();
        $this->travel(11)->minutes();
        Cache::put("wa:admin:pending:{$this->conv->id}", $kept, now()->addMinutes(5));
        $this->press("adm:{$nonce}:y", $wamid);
        BotEngine::reply($this->conv->fresh());
        $this->assertSame(0, WaKnowledgeEntry::count());
    }

    public function test_the_button_title_never_passes_the_text_confirmation(): void
    {
        $this->stage();
        $this->press('adm:AAAAAAAAAAAA:y', null);

        $out = AdminTools::execute('manage_knowledge', self::ADD + ['confirm' => true], $this->conv->fresh(), $this->admin->fresh());

        $this->assertSame('use_button', $out['error']);
        $this->assertSame(0, WaKnowledgeEntry::count());
        $this->assertNotNull($this->pending(), 'الرفض لا يلغي الإجراء المعلّق');
    }

    public function test_pressing_cancel_cancels(): void
    {
        $this->stage();
        ['nonce' => $nonce, 'wamid' => $wamid] = $this->pending();
        $this->press("adm:{$nonce}:n", $wamid, '✖️ إلغاء');

        BotEngine::reply($this->conv->fresh());

        $this->assertSame(0, WaKnowledgeEntry::count());
        $this->assertSame('أُلغي الإجراء، ولم يُنفَّذ شيء.', $this->lastText());
        $this->assertSame('denied', WaAdminAction::orderByDesc('id')->first()->status);
        $this->assertSame('ألغاه الموظف بالزر', WaAdminAction::orderByDesc('id')->first()->summary);
        $this->assertNull(Cache::get("wa:admin:pending:{$this->conv->id}"));
    }

    public function test_a_permission_revoked_before_the_press_is_denied(): void
    {
        $this->stage();
        ['nonce' => $nonce, 'wamid' => $wamid] = $this->pending();
        $this->admin->update(['permissions' => ['kb.view']]);
        $this->press("adm:{$nonce}:y", $wamid);

        BotEngine::reply($this->conv->fresh());

        $this->assertSame(0, WaKnowledgeEntry::count());
        $this->assertSame('manage_knowledge:add:denied', last($this->log()));
        $this->assertStringStartsWith('لم يُنفَّذ', $this->lastText());
    }

    public function test_typing_confirm_still_works_with_the_buttons(): void
    {
        $this->stage();
        $this->say('تأكيد');

        $out = AdminTools::execute('manage_knowledge', self::ADD + ['confirm' => true], $this->conv->fresh(), $this->admin->fresh());

        $this->assertTrue($out['done']);
        $this->assertSame(1, WaKnowledgeEntry::count());
    }

    public function test_with_the_flag_off_the_preview_stays_plain_text(): void
    {
        config(['features.env.admin_confirm_buttons' => false]);

        $this->stage();

        $req = collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn (Request $r) => str_contains($r->url(), '/messages'));
        $this->assertSame('text', $req['type']);
        $this->assertNull($this->pending()['wamid']);
    }
}
