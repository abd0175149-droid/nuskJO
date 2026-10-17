<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\WaLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * وضع التصنيف عبر الربط المقيّد (E29.4): خارج النطاق 404 بلا كتابة، والمعطّل والسادس 422، والعلم المطفأ 404،
 * وفلتر ?label= يضيّق داخل النطاق ولا يكشف محادثة غير مرئية، وكل سطر في القائمة يحمل شرائحه.
 */
class LabelAttachScopeTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.wa_labels' => true, 'audit.extended' => true]);
    }

    private function label(string $name, bool $active = true): WaLabel
    {
        return WaLabel::create(['name' => $name, 'color' => 'blue', 'is_active' => $active]);
    }

    public function test_out_of_scope_inactive_sixth_and_flag_off_are_refused_without_writing(): void
    {
        $umrah = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $mine = $this->conversationTagged(['umrah']);
        $foreign = $this->conversationTagged(['visa']);
        $vip = $this->label('VIP');

        $this->actingAs($umrah)->postJson("/api/whatsapp/conversations/{$foreign->id}/labels/{$vip->id}")->assertNotFound();
        $this->actingAs($umrah)->deleteJson("/api/whatsapp/conversations/{$foreign->id}/labels/{$vip->id}")->assertNotFound();
        $this->assertSame(0, DB::table('wa_conversation_labels')->count());

        $this->actingAs($umrah)->postJson("/api/whatsapp/conversations/{$mine->id}/labels/{$this->label('قديم', false)->id}")
            ->assertStatus(422)->assertJsonPath('errors.label.0', 'هذا التصنيف معطّل — اختر تصنيفاً فعّالاً');
        $this->actingAs($umrah)->postJson("/api/whatsapp/conversations/{$mine->id}/labels/999999")->assertNotFound();

        foreach (['أ أ', 'ب ب', 'ج ج', 'د د', 'هـ هـ'] as $name) {
            $this->actingAs($umrah)->postJson("/api/whatsapp/conversations/{$mine->id}/labels/{$this->label($name)->id}")->assertOk();
        }
        $this->actingAs($umrah)->postJson("/api/whatsapp/conversations/{$mine->id}/labels/{$vip->id}")->assertStatus(422);
        $this->assertSame(5, DB::table('wa_conversation_labels')->where('conversation_id', $mine->id)->count());
        $this->assertSame(5, AuditLog::where('action', 'wa.label_attach')->count());

        config(['features.env.wa_labels' => false]);
        $first = DB::table('wa_conversation_labels')->value('label_id');
        $this->actingAs($umrah)->deleteJson("/api/whatsapp/conversations/{$mine->id}/labels/{$first}")->assertNotFound();
        $this->assertSame(5, DB::table('wa_conversation_labels')->where('conversation_id', $mine->id)->count());
    }

    public function test_attach_is_idempotent_and_detach_returns_the_remaining_chips(): void
    {
        $agent = $this->staffWith('desk', self::INBOX_PERMISSIONS);
        $conv = $this->conversationTagged([], ['assigned_to' => $agent->id]);
        $a = $this->label('ينتظر جواز');
        $b = $this->label('دفع عربون');

        $this->actingAs($agent)->postJson("/api/whatsapp/conversations/{$conv->id}/labels/{$a->id}")->assertOk();
        $this->actingAs($agent)->postJson("/api/whatsapp/conversations/{$conv->id}/labels/{$a->id}")->assertOk();
        $this->actingAs($agent)->postJson("/api/whatsapp/conversations/{$conv->id}/labels/{$b->id}")
            ->assertOk()->assertJsonCount(2, 'labels');
        $this->actingAs($agent)->deleteJson("/api/whatsapp/conversations/{$conv->id}/labels/{$a->id}")
            ->assertOk()->assertExactJson(['labels' => [['id' => $b->id, 'name' => 'دفع عربون', 'color' => 'blue', 'active' => true]]]);
        $this->assertSame(2, AuditLog::where('action', 'wa.label_attach')->count(), 'التكرار لا يُدقَّق مرتين');
        $this->assertSame(1, AuditLog::where('action', 'wa.label_detach')->count());

        // الرد وحده يضع التصنيف: من يرى فقط لا
        $viewer = $this->staffWith('viewer', ['whatsapp.view', 'whatsapp.view_all']);
        $this->actingAs($viewer)->postJson("/api/whatsapp/conversations/{$conv->id}/labels/{$a->id}")->assertForbidden();
    }

    public function test_the_label_filter_narrows_within_the_scope_only(): void
    {
        $umrah = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $manager = $this->staffWith('manager', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $vip = $this->label('VIP');
        $other = $this->label('دفع عربون');
        $mine = $this->conversationTagged(['umrah']);
        $mineUnlabelled = $this->conversationTagged(['umrah']);
        $foreign = $this->conversationTagged(['visa']);
        foreach ([$mine, $foreign] as $conv) {
            DB::table('wa_conversation_labels')->insert(['conversation_id' => $conv->id, 'label_id' => $vip->id, 'created_at' => now()]);
        }

        $ids = fn ($user, $query) => array_column($this->actingAs($user)->getJson('/api/whatsapp/conversations?'.$query)->assertOk()->json('conversations'), 'id');
        $this->assertSame([$mine->id], $ids($umrah, "label={$vip->id}"));
        $this->assertSame([], $ids($umrah, "label={$other->id}"));
        $this->assertEqualsCanonicalizing([$mine->id, $mineUnlabelled->id], $ids($umrah, ''));
        $this->assertEqualsCanonicalizing([$mine->id, $foreign->id], $ids($manager, "label={$vip->id}"));

        $row = collect($this->actingAs($umrah)->getJson('/api/whatsapp/conversations')->json('conversations'))->firstWhere('id', $mine->id);
        $this->assertSame([['id' => $vip->id, 'name' => 'VIP', 'color' => 'blue', 'active' => true]], $row['labels']);
        $this->assertSame([], $row['badges']);

        // العلم مطفأ: لا فلتر ولا شرائح
        config(['features.env.wa_labels' => false]);
        $this->assertEqualsCanonicalizing([$mine->id, $mineUnlabelled->id], $ids($umrah, "label={$vip->id}"));
        $this->assertArrayNotHasKey('labels', $this->actingAs($umrah)->getJson('/api/whatsapp/conversations')->json('conversations.0'));
    }

    public function test_the_inbox_gets_the_active_list_and_the_flags(): void
    {
        config(['features.env.inbox_v2' => true, 'features.env.thread_pins' => true]);
        $manager = $this->staffWith('manager', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all', 'whatsapp.settings']);
        $this->label('VIP');
        $this->label('قديم', false);

        $this->actingAs($manager)->get('/whatsapp/inbox')->assertOk()->assertInertia(fn ($page) => $page
            ->where('labels', [['id' => 1, 'name' => 'VIP', 'color' => 'blue']])
            ->where('canManageLabels', true)
            ->where('flags', ['wa_labels' => true, 'thread_pins' => true, 'staff_translation' => false, 'conversation_panel' => false])
            ->where('translateTargets', []));
    }
}
