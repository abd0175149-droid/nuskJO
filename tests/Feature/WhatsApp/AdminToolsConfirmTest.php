<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaAdminAction;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaKnowledgeEntry;
use App\Models\WaMessage;
use App\Services\WhatsApp\AdminTools;
use App\Services\WhatsApp\BotEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * تعديلات المساعد الإداري على مرحلتين (INV-13):
 * معاينة تحفظ الإجراء، ثم موافقة صريحة من الموظف في رسالة جديدة، ثم تنفيذ المحفوظ لا ما يرسله النموذج.
 * والصلاحية تُفحص عند الاستدعاء وعند التأكيد، والمعاينة والتنفيذ والرفض والإلغاء تُسجَّل في wa_admin_actions.
 */
class AdminToolsConfirmTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    private WaAdminNumber $admin;

    private const ADD = ['action' => 'add', 'title' => 'مواعيد الدوام', 'content' => 'من التاسعة صباحاً إلى الخامسة مساءً.'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
        $this->conv = WaConversation::factory()->windowOpen()->create();
        $this->admin = WaAdminNumber::factory()->create(['phone' => $this->conv->phone]);
        $this->say('أضف معلومة عن مواعيد الدوام');
    }

    private function say(string $body): void
    {
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => $body]);
    }

    private function tool(string $tool, array $in, ?WaAdminNumber $admin = null): array
    {
        return AdminTools::execute($tool, $in, $this->conv->fresh(), $admin ?? $this->admin->fresh());
    }

    private function pendingKey(): string
    {
        return "wa:admin:pending:{$this->conv->id}";
    }

    /** @return list<string> «الأداة:الفعل:الحالة» بترتيب التسجيل */
    private function log(): array
    {
        return WaAdminAction::where('conversation_id', $this->conv->id)->orderBy('id')->get()
            ->map(fn ($a) => "{$a->tool}:{$a->action}:{$a->status}")->all();
    }

    #[Group('INV-13')]
    public function test_call_without_confirm_only_stages_a_preview(): void
    {
        $out = $this->tool('manage_knowledge', self::ADD);

        $this->assertTrue($out['needs_confirmation']);
        $this->assertStringContainsString('مواعيد الدوام', $out['preview']);
        $this->assertSame(0, WaKnowledgeEntry::count());

        $pending = Cache::get($this->pendingKey());
        $this->assertSame(['title' => self::ADD['title'], 'content' => self::ADD['content']], $pending['payload']);
        $this->assertSame($this->admin->id, $pending['admin']);

        $this->assertSame(['manage_knowledge:add:preview'], $this->log());
    }

    #[Group('INV-13')]
    public function test_confirm_without_a_preview_executes_nothing(): void
    {
        $out = $this->tool('manage_knowledge', self::ADD + ['confirm' => true]);

        $this->assertArrayHasKey('error', $out);
        $this->assertSame(0, WaKnowledgeEntry::count());
    }

    #[Group('INV-13')]
    public function test_confirm_without_a_new_message_from_the_admin_is_rejected(): void
    {
        $this->tool('manage_knowledge', self::ADD);

        // النموذج يؤكد في الدورة نفسها، قبل أن يردّ الموظف
        $out = $this->tool('manage_knowledge', self::ADD + ['confirm' => true]);

        $this->assertArrayHasKey('error', $out);
        $this->assertSame(0, WaKnowledgeEntry::count());
        $this->assertTrue(Cache::has($this->pendingKey()), 'المعاينة باقية تنتظر ردّ الموظف');
    }

    #[Group('INV-13')]
    public function test_new_non_affirmative_message_cancels_the_pending_action(): void
    {
        $this->tool('manage_knowledge', self::ADD);
        $this->say('لا، خليها هلق');

        $out = $this->tool('manage_knowledge', self::ADD + ['confirm' => true]);

        $this->assertTrue($out['cancelled']);
        $this->assertFalse(Cache::has($this->pendingKey()));
        $this->assertSame(0, WaKnowledgeEntry::count());
        $this->assertSame(['manage_knowledge:add:preview', 'manage_knowledge:add:denied'], $this->log());

        // «تأكيد» متأخرة لا تُحيي إجراءً أُلغي
        $this->say('تأكيد');
        $this->assertArrayHasKey('error', $this->tool('manage_knowledge', self::ADD + ['confirm' => true]));
        $this->assertSame(0, WaKnowledgeEntry::count());
    }

    #[Group('INV-13')]
    public function test_affirmative_message_executes_the_saved_payload_not_the_model_arguments(): void
    {
        $this->tool('manage_knowledge', self::ADD);
        $this->say('تأكيد');

        $out = $this->tool('manage_knowledge', [
            'action' => 'add', 'title' => 'عنوان غيّره النموذج', 'content' => 'نص غيّره النموذج', 'confirm' => true,
        ]);

        $this->assertTrue($out['done']);
        $entry = WaKnowledgeEntry::sole();
        $this->assertSame(self::ADD['title'], $entry->title);
        $this->assertSame(self::ADD['content'], $entry->content);
        $this->assertSame($this->admin->id, $entry->updated_by_admin_id);
        $this->assertFalse(Cache::has($this->pendingKey()));
        $this->assertSame(['manage_knowledge:add:preview', 'manage_knowledge:add:done'], $this->log());
    }

    #[Group('INV-13')]
    public function test_permission_revoked_between_preview_and_confirmation_is_denied(): void
    {
        $this->tool('manage_knowledge', self::ADD);
        $this->admin->update(['permissions' => ['kb.view']]);
        $this->say('تأكيد');

        $out = $this->tool('manage_knowledge', self::ADD + ['confirm' => true]);

        $this->assertSame('غير مسموح', $out['error']);
        $this->assertSame(0, WaKnowledgeEntry::count());
        $this->assertSame(['manage_knowledge:add:preview', 'manage_knowledge:add:denied'], $this->log());
    }

    /** الصلاحية تُقرأ من القاعدة عند التأكيد، لا من نسخة الرقم التي بدأ بها الطلب */
    #[Group('INV-13')]
    public function test_permission_revoked_while_the_request_is_in_flight_is_denied_at_commit(): void
    {
        $inFlight = $this->admin->fresh();
        $this->tool('manage_knowledge', self::ADD, $inFlight);
        WaAdminNumber::whereKey($this->admin->id)->update(['permissions' => json_encode(['kb.view'])]);
        $this->say('تأكيد');

        $out = $this->tool('manage_knowledge', self::ADD + ['confirm' => true], $inFlight);

        $this->assertSame('غير مسموح', $out['error']);
        $this->assertSame(0, WaKnowledgeEntry::count());
        $this->assertFalse(Cache::has($this->pendingKey()));
        $this->assertSame(['manage_knowledge:add:preview', 'manage_knowledge:add:denied'], $this->log());
    }

    #[Group('INV-13')]
    public function test_kb_permissions_split_view_from_edit(): void
    {
        $viewer = WaAdminNumber::factory()->withPermissions(['kb.view'])->create();
        $decl = collect(AdminTools::declarations($viewer))->keyBy('name');

        $this->assertSame(['list', 'view', 'search'], $decl['manage_knowledge']['input_schema']['properties']['action']['enum']);
        $this->assertArrayNotHasKey('error', $this->tool('manage_knowledge', ['action' => 'list'], $viewer));

        foreach (['add' => self::ADD, 'update' => ['action' => 'update', 'id' => 1], 'delete' => ['action' => 'delete', 'id' => 1]] as $action => $in) {
            $this->assertSame('غير مسموح', $this->tool('manage_knowledge', $in, $viewer)['error'] ?? null, "kb.view سمح بـ {$action}");
        }
        $this->assertFalse(Cache::has($this->pendingKey()));
        $this->assertSame(
            ['manage_knowledge:list:done', 'manage_knowledge:add:denied', 'manage_knowledge:update:denied', 'manage_knowledge:delete:denied'],
            $this->log(),
        );
    }

    #[Group('INV-13')]
    public function test_tools_toggle_is_needed_to_change_customer_bot_tools(): void
    {
        $viewer = WaAdminNumber::factory()->withPermissions(['tools.view'])->create();
        $this->assertSame(['list'], collect(AdminTools::declarations($viewer))->keyBy('name')['manage_bot_tools']['input_schema']['properties']['action']['enum']);
        $this->assertSame('غير مسموح', $this->tool('manage_bot_tools', ['action' => 'disable', 'tool' => 'save_note'], $viewer)['error']);

        $this->tool('manage_bot_tools', ['action' => 'disable', 'tool' => 'save_note']);
        $this->assertTrue(WaBotSetting::current()->toolEnabled('save_note'), 'المعاينة لا تغيّر شيئاً');
        $this->say('تأكيد');
        $this->assertTrue($this->tool('manage_bot_tools', ['action' => 'disable', 'tool' => 'save_note', 'confirm' => true])['done']);

        $this->assertFalse(WaBotSetting::current()->toolEnabled('save_note'));
        $this->assertSame(
            ['manage_bot_tools:disable:denied', 'manage_bot_tools:disable:preview', 'manage_bot_tools:disable:done'],
            $this->log(),
        );
    }

    #[Group('INV-13')]
    public function test_bot_toggle_is_needed_to_switch_the_customer_bot(): void
    {
        $reporter = WaAdminNumber::factory()->withPermissions(['report.daily'])->create();
        $this->assertNotContains('set_customer_bot', array_column(AdminTools::declarations($reporter), 'name'));
        $this->assertSame('غير مسموح', $this->tool('set_customer_bot', ['action' => 'off'], $reporter)['error']);
        $this->assertTrue(WaBotSetting::current()->enabled);

        $this->tool('set_customer_bot', ['action' => 'off']);
        $this->assertTrue(WaBotSetting::current()->enabled, 'المعاينة لا توقف البوت');
        $this->say('أكد');
        $this->assertTrue($this->tool('set_customer_bot', ['action' => 'off', 'confirm' => true])['done']);

        $this->assertFalse(WaBotSetting::current()->enabled);
        $this->assertSame(
            ['set_customer_bot:off:denied', 'set_customer_bot:off:preview', 'set_customer_bot:off:done'],
            $this->log(),
        );
    }

    #[Group('INV-13')]
    public function test_unknown_tool_or_action_is_denied_and_logged(): void
    {
        $this->assertSame('غير مسموح', $this->tool('approve_invoice', ['id' => 1])['error']);
        $this->assertSame('غير مسموح', $this->tool('manage_knowledge', ['action' => 'drop_all'])['error']);

        $this->assertSame(['approve_invoice::denied', 'manage_knowledge:drop_all:denied'], $this->log());
    }

    /** طرف لطرف عبر حلقة النموذج: المعاينة في دورة، والتأكيد في دورة تالية بعد ردّ الموظف */
    #[Group('INV-13')]
    public function test_two_turn_flow_through_the_model_executes_what_the_admin_saw(): void
    {
        $this->fakeGraph();
        $this->fakeGemini(
            ['call' => 'manage_knowledge', 'args' => self::ADD],
            'المعاينة: إضافة «مواعيد الدوام». ردّ بكلمة «تأكيد» للتنفيذ.',
            ['call' => 'manage_knowledge', 'args' => ['action' => 'add', 'title' => 'مواعيد الدوام', 'content' => 'نص آخر', 'confirm' => true]],
            'تمت الإضافة.',
        );

        $this->assertTrue(BotEngine::reply($this->conv->fresh())['sent']);
        $this->assertSame(0, WaKnowledgeEntry::count());

        $this->say('تأكيد');
        $this->assertTrue(BotEngine::reply($this->conv->fresh())['sent']);

        $this->assertSame(self::ADD['content'], WaKnowledgeEntry::sole()->content);
        $this->assertSame(['manage_knowledge:add:preview', 'manage_knowledge:add:done'], $this->log());
    }
}
