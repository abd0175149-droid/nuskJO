<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaBlock;
use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * مسارات الحظر (E31.5/E31.6): whatsapp.block مفحوصة في الخادم في كل دالة فوق الربط المقيّد بالنطاق، والعلم مطفأ 404،
 * والتأكيد بآخر 4 أرقام والسبب الإلزامي، وحد 10 في الدقيقة، وشريط المحادثة المحظورة في الخيط.
 */
class BlockRoutesTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const BODY = ['reason_code' => 'abuse', 'reason' => 'شتائم متكررة للموظفين في المحادثة', 'confirm' => '1234'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.wa_block' => true]);
    }

    private function fakeBlockOk(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/block_users_ok.json')), true))
            ->push(json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/block_users_removed.json')), true))]);
    }

    private function customer(): WaConversation
    {
        return WaConversation::factory()->windowOpen()->create(['phone' => '16505551234', 'wa_phone' => '16505551234']);
    }

    private function block(User $u, WaConversation $c, array $body = self::BODY)
    {
        return $this->actingAs($u)->postJson("/api/whatsapp/conversations/{$c->id}/block", $body);
    }

    public function test_blocking_needs_the_block_permission_itself(): void
    {
        Http::fake();
        $conv = $this->customer();
        // يرى المحادثة (موكلة إليه) ولا يملك الحظر
        $desk = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply'], 'desk')->create();
        $conv->forceFill(['assigned_to' => $desk->id])->save();

        $this->block($desk, $conv)->assertForbidden();
        $this->block(User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all', 'whatsapp.settings'], 'lead')->create(), $conv)->assertForbidden();
        $this->actingAs(User::factory()->withPermissions(['whatsapp.view_all'], 'lead2')->create())->get('/whatsapp/blocks')->assertForbidden();
        Http::assertNothingSent();
        $this->assertNull($conv->fresh()->blocked_at);
    }

    public function test_the_routes_do_not_exist_while_the_flag_is_off(): void
    {
        config(['features.env.wa_block' => false]);
        $admin = User::factory()->admin()->create();

        $this->block($admin, $this->customer())->assertNotFound();
        $this->actingAs($admin)->get('/whatsapp/blocks')->assertNotFound();
        $this->actingAs($admin)->postJson('/api/whatsapp/blocks/sync')->assertNotFound();
    }

    public function test_a_conversation_outside_the_staff_scope_is_not_found(): void
    {
        Http::fake();
        $scoped = $this->staffWith('umrah-desk', ['whatsapp.view', 'whatsapp.block'], ['umrah']);
        $conv = $this->conversationTagged(['visa'], ['phone' => '16505551234', 'wa_phone' => '16505551234']);

        $this->block($scoped, $conv)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_the_confirmation_and_the_reason_are_checked_on_the_server(): void
    {
        Http::fake();
        $admin = User::factory()->admin()->create();
        $conv = $this->customer();

        $this->block($admin, $conv, ['confirm' => '9999'] + self::BODY)->assertStatus(422)->assertJsonPath('code', 'CONFIRM_MISMATCH');
        $this->block($admin, $conv, ['reason' => 'قصير'] + self::BODY)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->block($admin, $conv, ['reason_code' => 'enemy'] + self::BODY)->assertStatus(422)->assertJsonValidationErrors('reason_code');
        Http::assertNothingSent();
    }

    public function test_block_then_unblock_from_the_inbox(): void
    {
        $this->fakeBlockOk();
        $admin = User::factory()->admin()->create(['name' => 'المدير']);
        $conv = $this->customer();

        $this->block($admin, $conv)->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['blocked_at']);

        // الخيط: شريط بمن حظر ومتى ولماذا، والمنشئ يعرف أنها محظورة
        $thread = $this->actingAs($admin)->getJson("/api/whatsapp/conversations/{$conv->id}/messages")->assertOk();
        $this->assertTrue($thread->json('conversation.blocked'));
        $this->assertSame(['المدير', 'إساءة', self::BODY['reason']], [$thread->json('conversation.block.by'), $thread->json('conversation.block.reason_label'), $thread->json('conversation.block.reason')]);

        $this->actingAs($admin)->deleteJson("/api/whatsapp/conversations/{$conv->id}/block", ['reason' => 'قصير'])->assertStatus(422);
        $this->actingAs($admin)->deleteJson("/api/whatsapp/conversations/{$conv->id}/block", ['reason' => 'اعتذر العميل كتابياً'])->assertOk();
        $this->assertNull($conv->fresh()->blocked_at);
        $this->actingAs($admin)->deleteJson("/api/whatsapp/conversations/{$conv->id}/block", ['reason' => 'مرة ثانية للتأكد'])->assertStatus(422)->assertJsonPath('code', 'NOT_BLOCKED');
    }

    public function test_the_reason_text_is_shown_to_block_holders_only(): void
    {
        $conv = $this->customer();
        $conv->forceFill(['blocked_at' => now()])->save();
        WaBlock::create(['conversation_id' => $conv->id, 'phone' => '16505551234', 'reason_code' => 'fraud', 'reason' => 'انتحال صفة موظف لدينا', 'blocked_at' => now()]);
        $viewer = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all'], 'viewer')->create();

        $thread = $this->actingAs($viewer)->getJson("/api/whatsapp/conversations/{$conv->id}/messages")->assertOk();

        $this->assertSame('احتيال أو انتحال', $thread->json('conversation.block.reason_label'));
        $this->assertNull($thread->json('conversation.block.reason'));
    }

    public function test_blocking_is_limited_to_ten_requests_a_minute(): void
    {
        Http::fake();
        $admin = User::factory()->admin()->create();
        $conv = $this->customer();

        foreach (range(1, 10) as $_) {
            $this->block($admin, $conv, ['confirm' => '0000'] + self::BODY)->assertStatus(422);
        }
        $this->block($admin, $conv, ['confirm' => '0000'] + self::BODY)->assertStatus(429);
    }

    public function test_the_privacy_page_mentions_official_blocking(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('أداة الحظر الرسمية في واتساب')->assertSee("WhatsApp's official blocking tool", false);
    }
}
