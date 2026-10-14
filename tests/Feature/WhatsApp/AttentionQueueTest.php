<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Client;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\AdminTools;
use App\Services\WhatsApp\TopicRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * get_attention_queue (E15.8): المحادثات التي تنتظر موظفاً ببيانات وصفية فقط. لا نص من رسائل العملاء ولا رقم ولا اسم
 * كتبه العميل، ومحادثات الإدارة والممحوّة والمدموجة مستثناة، والصلاحية report.attention مطلوبة.
 */
class AttentionQueueTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const SECRET = 'نص سري كتبه العميل';

    private WaConversation $adminConv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
        $this->adminConv = WaConversation::factory()->windowOpen()->create();
    }

    private function waiting(array $attrs, int $minutesAgo): WaConversation
    {
        $c = WaConversation::factory()->create($attrs + ['needs_attention' => true, 'last_inbound_at' => now()->subMinutes($minutesAgo)]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $c->id, 'body' => self::SECRET.' 0791234567']);

        return $c;
    }

    private function queue(WaAdminNumber $a): array
    {
        return AdminTools::execute('get_attention_queue', [], $this->adminConv->fresh(), $a->fresh());
    }

    public function test_the_queue_has_metadata_only_oldest_first(): void
    {
        $agent = User::factory()->create(['name' => 'موظفة المبيعات']);
        $linked = $this->waiting(['client_id' => Client::factory()->create(['name' => 'عميل مربوط'])->id, 'display_name' => 'اسم من واتساب',
            'assigned_to' => $agent->id, 'attention_reason' => 'handoff'], 30);
        TopicRouter::tag($linked, 'umrah');
        $unlinked = $this->waiting(['wa_profile_name' => 'Profile Name', 'display_name' => 'اسم آخر من واتساب'], 90);

        $admin = $this->waiting([], 200);
        $admin->forceFill(['is_admin_chat' => true])->save();
        $this->waiting(['erased_at' => now()], 300);
        $this->waiting(['merged_into_id' => $linked->id], 400);
        WaConversation::factory()->create(['needs_attention' => false, 'last_inbound_at' => now()->subHours(5)]);

        $out = $this->queue(WaAdminNumber::factory()->withPermissions(['report.attention'])->create());

        $this->assertSame(2, $out['count']);
        [$first, $second] = $out['conversations'];
        $this->assertSame($unlinked->id, $first['id'], 'الأقدم انتظاراً أولاً');
        $this->assertSame('محادثة #'.$unlinked->id, $first['label']);
        $this->assertSame('غير مُسند', $first['assignee']);
        $this->assertEqualsWithDelta(90, $first['waiting_minutes'], 1);

        $this->assertSame('عميل مربوط', $second['label']);
        $this->assertSame(['عمرة'], $second['topics']);
        $this->assertSame('موظفة المبيعات', $second['assignee']);
        $this->assertSame('🙋 طلب موظفاً', $second['reason']);
        $this->assertIsInt($second['window_minutes_left']);
        $this->assertStringEndsWith('/whatsapp/inbox?conv='.$linked->id, $second['url']);

        $json = json_encode($out, JSON_UNESCAPED_UNICODE);
        foreach ([self::SECRET, '0791234567', $linked->phone, $unlinked->phone, 'Profile Name', 'اسم من واتساب', 'اسم آخر من واتساب'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }

    public function test_the_permission_is_required(): void
    {
        $this->waiting([], 10);
        $reporter = WaAdminNumber::factory()->withPermissions(['report.daily'])->create();

        $this->assertNotContains('get_attention_queue', array_column(AdminTools::declarations($reporter), 'name'));
        $this->assertSame('غير مسموح', $this->queue($reporter)['error']);
    }

    public function test_once_enforced_the_linked_user_needs_to_see_all_conversations(): void
    {
        $this->waiting([], 10);
        config(['features.env.admin_rbac_enforce' => true]);

        $limited = WaAdminNumber::factory()->withPermissions(['report.attention'])
            ->create(['user_id' => User::factory()->withPermissions(['whatsapp.view'])->create()->id]);
        $this->assertSame('غير مسموح', $this->queue($limited)['error']);

        $all = WaAdminNumber::factory()->withPermissions(['report.attention'])
            ->create(['user_id' => User::factory()->withPermissions(['whatsapp.view_all'], 'supervisor')->create()->id]);
        $this->assertSame(1, $this->queue($all)['count']);
    }
}
