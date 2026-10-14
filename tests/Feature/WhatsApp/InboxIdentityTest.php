<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\IdentityMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الصندوق والإعدادات مع الهوية (E11.9): المدموجة لا تظهر، والبحث باسم المستخدم واللقب، وحقول الهوية والإعلان،
 * والاقتباس من المحادثة نفسها، والوسائط بلا رابط، ومفتاح الوضع بصلاحية الإعدادات وبسجل.
 */
class InboxIdentityTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all', 'whatsapp.reply'], 'desk')->create();
    }

    private function list(array $query = []): array
    {
        return $this->actingAs($this->staff)->getJson('/api/whatsapp/conversations?'.http_build_query($query))->assertOk()->json('conversations');
    }

    public function test_the_list_hides_merged_conversations_and_carries_identity_fields(): void
    {
        $client = Client::factory()->create(['code' => 'C-77']);
        $ad = WaConversation::factory()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => 'US.1', 'display_name' => null,
            'wa_username' => 'traveler', 'wa_profile_name' => 'أبو خالد', 'referral' => ['source_type' => 'ad'], 'client_id' => $client->id]);
        WaConversation::factory()->create(['status' => 'merged']);

        $rows = $this->list();

        $this->assertSame([$ad->id], array_column($rows, 'id'));
        $row = $rows[0];
        $this->assertSame([true, 'traveler', 'أبو خالد', true, 'C-77', false, null],
            [$row['phoneless'], $row['username'], $row['profile_name'], $row['from_ad'], $row['client_code'], $row['is_admin'], $row['phone']]);
        $this->assertSame($client->name, $row['name'], 'الاسم = label(): العميل المربوط أولاً');
    }

    public function test_search_covers_username_and_whatsapp_name(): void
    {
        $a = WaConversation::factory()->create(['wa_username' => 'umrah.fan']);
        $b = WaConversation::factory()->create(['wa_profile_name' => 'حاج سعيد']);

        $this->assertSame([$a->id], array_column($this->list(['search' => 'umrah.f']), 'id'));
        $this->assertSame([$b->id], array_column($this->list(['search' => 'سعيد']), 'id'));
    }

    public function test_messages_carry_system_flag_quote_and_media_without_links(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $other = WaConversation::factory()->create();
        WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'wamid' => 'wamid.MINE', 'body' => str_repeat('عرض ', 40)]);
        WaMessage::factory()->outbound()->create(['conversation_id' => $other->id, 'wamid' => 'wamid.THEIRS', 'body' => 'سر']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'reply_to_wamid' => 'wamid.MINE', 'body' => 'نعم']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'reply_to_wamid' => 'wamid.THEIRS', 'body' => 'وهذا']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'msg_type' => 'document', 'body' => '📄 ملف: جواز.pdf',
            'media_id' => 'media.1', 'media_mime' => 'application/pdf', 'media_filename' => 'جواز.pdf']);
        WaMessage::factory()->create(['conversation_id' => $conv->id, 'direction' => 'in', 'source' => 'system', 'msg_type' => 'system', 'body' => '🔄 غيّر العميل رقمه']);

        $res = $this->actingAs($this->staff)->getJson("/api/whatsapp/conversations/{$conv->id}/messages")->assertOk();
        $m = collect($res->json('messages'))->keyBy('body');

        $this->assertSame(80, mb_strlen($m['نعم']['reply_to']));
        $this->assertNull($m['وهذا']['reply_to'], 'لا اقتباس من محادثة أخرى');
        $this->assertSame(['mime' => 'application/pdf', 'filename' => 'جواز.pdf'], $m['📄 ملف: جواز.pdf']['media']);
        $this->assertTrue($m['🔄 غيّر العميل رقمه']['is_system']);
        $this->assertFalse($m['نعم']['is_system']);
        $this->assertStringNotContainsString('media.1', $res->getContent());
        $this->assertSame(['store', false], [$res->json('conversation.identity_mode'), $res->json('conversation.phoneless')]);
    }

    public function test_the_inbox_page_passes_link_permission_and_mode(): void
    {
        Setting::set('wa_identity_mode', IdentityMode::STAFF);
        $linker = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.link_client', 'clients.view'], 'linker')->create();

        $this->actingAs($linker)->get('/whatsapp/inbox')->assertInertia(fn ($page) => $page
            ->where('canLinkClient', true)->where('identityMode', 'staff'));
        $this->actingAs($this->staff)->get('/whatsapp/inbox')->assertInertia(fn ($page) => $page->where('canLinkClient', false));
    }

    public function test_the_identity_mode_switch_needs_settings_and_is_audited(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $this->actingAs($this->staff)->patchJson('/api/whatsapp/settings/identity-mode', ['mode' => 'full'])->assertForbidden();
        $this->actingAs($owner)->patchJson('/api/whatsapp/settings/identity-mode', ['mode' => 'everyone'])->assertJsonValidationErrors('mode');
        $this->actingAs($owner)->patchJson('/api/whatsapp/settings/identity-mode', ['mode' => 'staff'])->assertOk()->assertJsonPath('identityMode', 'staff');
        $this->actingAs($owner)->patchJson('/api/whatsapp/settings/identity-mode', ['mode' => 'staff'])->assertOk();

        $this->assertSame(IdentityMode::STAFF, IdentityMode::current());
        $row = AuditLog::where('action', 'wa.identity_mode')->sole();
        $this->assertSame([['from' => 'store', 'to' => 'staff'], $owner->id], [$row->changes, $row->user_id]);
        $this->actingAs($owner)->get('/whatsapp/settings')->assertInertia(fn ($page) => $page->where('identityMode', 'staff'));
    }
}
