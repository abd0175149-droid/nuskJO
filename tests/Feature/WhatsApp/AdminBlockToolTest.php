<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaAdminAction;
use App\Models\WaAdminNumber;
use App\Models\WaBlock;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\AdminTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * أداة «حظر رقم مزعج» في المساعد الإداري (E31.7): خلف العلم wa_block_admin_tool، وبصلاحية الرقم مع صلاحية المستخدم
 * المرتبط (whatsapp.block) دائماً، ومعاينة ثم «تأكيد» في رسالة جديدة تنفّذ المحفوظ، والمطابقة بالرقم الكامل وحده.
 */
class AdminBlockToolTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $adminChat;

    private WaAdminNumber $admin;

    private WaConversation $customer;

    private const IN = ['action' => 'block', 'phone' => '+962 79 123 4567', 'reason_code' => 'spam', 'reason' => 'رسائل مزعجة متكررة طوال اليوم'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->adminChat = WaConversation::factory()->windowOpen()->create();
        $this->admin = WaAdminNumber::factory()->create(['phone' => $this->adminChat->phone, 'user_id' => User::factory()->admin()->create()->id]);
        $this->customer = WaConversation::factory()->windowOpen()->create(['phone' => '962791234567', 'wa_phone' => '962791234567', 'display_name' => 'رقم مزعج']);
        $this->say('احظر الرقم المزعج');
        Http::fake(['graph.facebook.com/*' => fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/block_users')
            ? Http::response(['messaging_product' => 'whatsapp', 'block_users' => ['added_users' => [['input' => '+962791234567', 'wa_id' => '962791234567']]]])
            : Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.OUT.'.uniqid()]]])]);
    }

    private function say(string $body): void
    {
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->adminChat->id, 'body' => $body]);
    }

    private function tool(array $in, ?WaAdminNumber $admin = null): array
    {
        return AdminTools::execute('block_contact', $in, $this->adminChat->fresh(), $admin ?? $this->admin->fresh());
    }

    private function blockCalls(): int
    {
        return count(Http::recorded(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/block_users')));
    }

    public function test_without_the_flag_the_tool_is_neither_declared_nor_run(): void
    {
        $this->assertNotContains('block_contact', array_column(AdminTools::declarations($this->admin), 'name'));
        $this->assertArrayHasKey('error', $this->tool(self::IN));
        $this->assertSame(0, $this->blockCalls());

        config(['features.env.wa_block_admin_tool' => true]);
        $this->assertContains('block_contact', array_column(AdminTools::declarations($this->admin), 'name'));
    }

    public function test_a_number_whose_user_cannot_block_is_denied(): void
    {
        config(['features.env.wa_block_admin_tool' => true]);
        $weak = WaAdminNumber::factory()->create(['user_id' => User::factory()->withPermissions(['whatsapp.view'], 'desk')->create()->id]);
        $unlinked = WaAdminNumber::factory()->create(['user_id' => null]);

        foreach ([$weak, $unlinked] as $number) {
            $this->assertSame('غير مسموح', $this->tool(self::IN, $number)['error']);
        }
        $this->assertSame(0, $this->blockCalls());
        $this->assertSame(0, WaBlock::count());
        $this->assertSame(['denied', 'denied'], WaAdminAction::where('tool', 'block_contact')->pluck('status')->all());
    }

    public function test_preview_then_confirm_in_a_new_message_blocks_what_was_previewed(): void
    {
        config(['features.env.wa_block_admin_tool' => true]);

        $preview = $this->tool(self::IN);
        $this->assertTrue($preview['needs_confirmation']);
        $sent = WaMessage::where('conversation_id', $this->adminChat->id)->where('direction', 'out')->sole();
        $this->assertStringContainsString('سأحظر: رقم مزعج (…4567)', $sent->body);
        $this->assertStringContainsString('إزعاج متكرر', $sent->body);
        $this->assertSame(0, $this->blockCalls());

        // النموذج يؤكد قبل ردّ الموظف: لا شيء
        $this->assertArrayHasKey('error', $this->tool(self::IN + ['confirm' => true]));
        $this->assertSame(0, $this->blockCalls());

        // الموظف يردّ «تأكيد»، والنموذج يرسل رقماً آخر: يُنفَّذ المحفوظ عند المعاينة
        $this->say('تأكيد');
        $out = $this->tool(['phone' => '962795555555', 'confirm' => true] + self::IN);

        $this->assertTrue($out['done']);
        $this->assertSame(1, $this->blockCalls());
        $block = WaBlock::sole();
        $this->assertSame([$this->customer->id, 'admin_number', $this->admin->id, $this->admin->user_id], [$block->conversation_id, $block->blocked_via, $block->admin_number_id, $block->blocked_by]);
        $this->assertSame('admin_number', AuditLog::where('action', 'wa.block')->sole()->actor_type);
        $this->assertNotNull($this->customer->fresh()->blocked_at);
    }

    public function test_only_the_full_canonical_number_matches(): void
    {
        config(['features.env.wa_block_admin_tool' => true]);

        // آخر 9 أرقام نفسها برمز دولة آخر
        $this->assertSame('لا توجد محادثة لهذا الرقم', $this->tool(['phone' => '966791234567'] + self::IN)['error']);
        // الصيغة المحلية الأردنية هي الرقم الكامل نفسه
        $this->assertTrue($this->tool(['phone' => '0791234567'] + self::IN)['needs_confirmation']);
    }

    public function test_targets_that_cannot_be_blocked_are_refused_before_any_preview(): void
    {
        config(['features.env.wa_block_admin_tool' => true]);
        $this->customer->forceFill(['last_inbound_at' => now()->subDays(2)])->save();

        $this->assertStringContainsString('خلال 24 ساعة', $this->tool(self::IN)['error']);
        $this->assertSame(0, WaMessage::where('conversation_id', $this->adminChat->id)->where('direction', 'out')->count());
    }
}
