<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaAdminAction;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaKnowledgeEntry;
use App\Models\WaMessage;
use App\Services\WhatsApp\AdminTools;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\Tools\ToolInvoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * ضوابط المساعد الإداري (E15.5): وضع القراءة فقط، وتقاطع صلاحيات الرقم مع دور المستخدم المرتبط (تسجيل ثم فرض)،
 * والمعاينة يرسلها النظام بنصها، والتأكيد بكلمة «تأكيد» وحدها.
 */
class AdminControlsTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    private const ADD = ['action' => 'add', 'title' => 'مواعيد الدوام', 'content' => 'من التاسعة صباحاً إلى الخامسة مساءً.'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
        $this->fakeGraph();
        $this->conv = WaConversation::factory()->windowOpen()->create();
        $this->say('ابدأ');
    }

    private function say(string $body): void
    {
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => $body]);
    }

    private function number(array $permissions, ?User $user = null): WaAdminNumber
    {
        return WaAdminNumber::factory()->withPermissions($permissions)->create(['phone' => $this->conv->phone, 'user_id' => $user?->id]);
    }

    private function tool(WaAdminNumber $a, string $tool, array $in): array
    {
        return AdminTools::execute($tool, $in, $this->conv->fresh(), $a->fresh());
    }

    /** @return array<string, list<string>> */
    private function enums(WaAdminNumber $a): array
    {
        return collect(AdminTools::declarations($a->fresh()))
            ->mapWithKeys(fn ($d) => [$d['name'] => $d['input_schema']['properties']['action']['enum'] ?? ['_']])->all();
    }

    private function lastLog(): WaAdminAction
    {
        return WaAdminAction::where('conversation_id', $this->conv->id)->orderByDesc('id')->firstOrFail();
    }

    private function enforce(): void
    {
        config(['features.env.admin_rbac_enforce' => true]);
    }

    // ==================== وضع القراءة فقط ====================

    public function test_readonly_hides_every_write_action_and_rejects_it(): void
    {
        $a = $this->number(array_keys(AdminTools::CATALOG), User::factory()->admin()->create());
        config(['features.env.admin_readonly' => true]);

        $enums = $this->enums($a);
        $this->assertSame(['list', 'view', 'search'], $enums['manage_knowledge']);
        $this->assertSame(['list'], $enums['manage_bot_tools']);
        $this->assertSame(['status'], $enums['set_customer_bot']);

        $out = $this->tool($a, 'manage_knowledge', self::ADD);
        $this->assertSame('غير مسموح', $out['error']);
        $this->assertSame('denied', $this->lastLog()->status);
        $this->assertSame('وضع القراءة فقط مفعّل', $this->lastLog()->summary);

        $run = ToolInvoker::run('manage_knowledge', self::ADD, $this->conv->fresh(), ['manage_knowledge'], $a->fresh());
        $this->assertSame('denied', $run['status']);
        $this->assertSame(0, WaKnowledgeEntry::count());
        Http::assertNothingSent();
    }

    public function test_readonly_switched_on_after_the_preview_blocks_the_confirmation(): void
    {
        $a = $this->number(['kb.add'], User::factory()->admin()->create());
        $this->assertTrue($this->tool($a, 'manage_knowledge', self::ADD)['needs_confirmation']);

        config(['features.env.admin_readonly' => true]);
        $this->say('تأكيد');

        $this->assertSame('غير مسموح', $this->tool($a, 'manage_knowledge', self::ADD + ['confirm' => true])['error']);
        $this->assertSame(0, WaKnowledgeEntry::count());
    }

    public function test_the_admin_card_states_readonly_mode(): void
    {
        $this->number(['report.daily', 'kb.add'], User::factory()->admin()->create());
        config(['features.env.admin_readonly' => true]);
        $this->fakeGemini('أهلاً');

        $this->assertTrue(BotEngine::reply($this->conv->fresh())['sent']);

        $prompt = Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage'))
            ->map(fn ($p) => json_encode($p[0]->data(), JSON_UNESCAPED_UNICODE))->implode('');
        $this->assertStringContainsString('وضع القراءة فقط مفعّل', $prompt);
        // سطر البطاقة يُنهيه سطر جديد (\n في JSON): الكتابة لا تُذكر في المسموح له
        $this->assertStringContainsString('المسموح له: ملخّص اليوم\\n', $prompt);
    }

    // ==================== تقاطع RBAC ====================

    public function test_every_catalog_permission_names_the_role_permission_it_needs(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(AdminTools::CATALOG), array_keys(AdminTools::RBAC_REQUIRES));
    }

    public function test_a_number_without_a_linked_user_is_read_only_once_enforced(): void
    {
        $a = $this->number(['report.daily', 'kb.view', 'kb.add']);
        $this->assertSame(['report.daily', 'kb.view'], $a->effectivePermissions());

        $this->enforce();
        $this->assertSame(['list', 'view', 'search'], $this->enums($a)['manage_knowledge']);
        $this->assertArrayNotHasKey('error', $this->tool($a, 'get_daily_summary', []));

        $this->assertSame('غير مسموح', $this->tool($a, 'manage_knowledge', self::ADD)['error']);
        $this->assertSame('بلا مستخدم مرتبط فعّال: قراءة فقط', $this->lastLog()->summary);
    }

    public function test_log_mode_records_what_would_be_denied_and_still_runs_it(): void
    {
        $sales = User::factory()->withPermissions(['reports.view'])->create();
        $a = $this->number(['kb.add'], $sales);

        $this->assertTrue($this->tool($a, 'manage_knowledge', self::ADD)['needs_confirmation']);
        $this->assertStringStartsWith('denied_would_be: دور المستخدم المرتبط لا يملك: whatsapp.settings | ', $this->lastLog()->summary);
        $this->assertSame('preview', $this->lastLog()->status);

        $this->say('تأكيد');
        $this->assertTrue($this->tool($a, 'manage_knowledge', self::ADD + ['confirm' => true])['done']);
        $this->assertSame(1, WaKnowledgeEntry::count());
        $this->assertStringStartsWith('denied_would_be: ', $this->lastLog()->summary);
    }

    public function test_enforced_mode_denies_what_the_linked_user_cannot_do(): void
    {
        $sales = User::factory()->withPermissions(['reports.view'])->create();
        $a = $this->number(['report.daily', 'report.sales', 'kb.add'], $sales);
        $this->enforce();

        $this->assertSame(['get_daily_summary'], array_keys($this->enums($a)));
        $this->assertArrayNotHasKey('error', $this->tool($a, 'get_daily_summary', []));

        $this->assertSame('غير مسموح', $this->tool($a, 'get_sales_report', [])['error'], 'تقرير الربح يتطلب reports.employee_profit');
        $this->assertSame('دور المستخدم المرتبط لا يملك: reports.employee_profit', $this->lastLog()->summary);
        $this->assertSame('غير مسموح', $this->tool($a, 'manage_knowledge', self::ADD)['error']);
        $this->assertSame(0, WaKnowledgeEntry::count());
    }

    public function test_an_administrator_supports_every_permission(): void
    {
        $a = $this->number(array_keys(AdminTools::CATALOG), User::factory()->admin()->create());

        $this->assertEqualsCanonicalizing(array_keys(AdminTools::CATALOG), $a->effectivePermissions());
        $this->assertSame([], AdminTools::permissionWarnings($a));
    }

    public function test_the_admin_numbers_page_shows_effective_permissions_and_warnings(): void
    {
        $sales = User::factory()->withPermissions(['reports.view'])->create();
        $this->number(['report.daily', 'kb.add'], $sales);

        $this->actingAs(User::factory()->admin()->create())->get('/whatsapp/admin-numbers')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('numbers.0.effective', ['report.daily'])
                ->where('numbers.0.warnings.0', fn ($w) => str_contains($w, 'إضافة معلومة') && str_contains($w, 'whatsapp.settings'))
                ->where('modes.rbacEnforced', false)
                ->where('modes.readonly', false));
    }

    // ==================== المعاينة والتأكيد ====================

    public function test_the_preview_is_sent_by_the_system_with_its_exact_text(): void
    {
        $a = $this->number(['kb.add'], User::factory()->admin()->create());

        $out = $this->tool($a, 'manage_knowledge', self::ADD);

        $this->assertTrue($out['preview_sent']);
        $this->assertArrayNotHasKey('preview', $out, 'النموذج لا يُعطى نص المعاينة ليعيد صياغته');
        $preview = $this->lastLog()->summary;
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com')
            && ($r['text']['body'] ?? null) === AdminTools::previewMessage($preview));
        $this->assertStringContainsString(self::ADD['content'], $preview);
        $this->assertSame('system', $this->conv->messages()->where('direction', 'out')->sole()->source);
    }

    public function test_loose_words_no_longer_confirm_and_the_explicit_word_does(): void
    {
        $a = $this->number(['kb.add'], User::factory()->admin()->create());

        $this->tool($a, 'manage_knowledge', self::ADD);
        $this->say('تمام');
        $this->assertTrue($this->tool($a, 'manage_knowledge', self::ADD + ['confirm' => true])['cancelled']);
        $this->assertSame(0, WaKnowledgeEntry::count());

        $this->tool($a, 'manage_knowledge', self::ADD);
        $this->say('تأكيد');
        $this->assertTrue($this->tool($a, 'manage_knowledge', self::ADD + ['confirm' => true])['done']);
        $this->assertSame(1, WaKnowledgeEntry::count());
    }
}
