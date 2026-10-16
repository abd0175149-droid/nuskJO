<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * البحث في الرسائل محصور بالسياج في الخادم قبل الحد والإزاحة (E30.3، E04): المختص يرى محادثات اختصاصه وما وُكّل له،
 * ومحادثات أرقام الإدارة لمن يملك whatsapp.admin_chats وحده، وtotal لا يعدّ المخفي، ولا هاتف في النتائج.
 * العلم message_search للمدراء (view_all) أولاً، وmessage_search_all يفتحه للجميع.
 */
class MessageSearchScopeTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.message_search' => true, 'features.env.message_search_all' => true]);
    }

    private function say(WaConversation $c, string $body, array $attrs = []): WaMessage
    {
        return WaMessage::factory()->create(['conversation_id' => $c->id, 'body' => $body] + $attrs);
    }

    private function search(User $user, array $params)
    {
        return $this->actingAs($user)->getJson('/api/whatsapp/search?'.http_build_query($params));
    }

    public function test_a_specialist_finds_words_only_in_conversations_of_their_topic_or_assigned_to_them(): void
    {
        $umrah = $this->staffWith('umrah-desk', ['whatsapp.view'], ['umrah']);
        $mine = $this->conversationTagged(['umrah']);
        $assigned = $this->conversationTagged([], ['assigned_to' => $umrah->id]);
        $visa = $this->conversationTagged(['visa']);
        $untagged = $this->conversationTagged();
        $a = $this->say($mine, 'نريد زعفران الحرم');
        $b = $this->say($assigned, 'وزعفران آخر');
        $this->say($visa, 'زعفران في محادثة التأشيرة');
        $this->say($untagged, 'زعفران بلا وسم');

        $response = $this->search($umrah, ['q' => 'زعفران'])->assertOk();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($response->json('hits'), 'message_id'));
        $this->assertSame(2, $response->json('total'));
        $this->assertFalse($response->json('total_capped'));
    }

    public function test_view_all_without_admin_chats_does_not_reach_an_admin_number_conversation(): void
    {
        WaAdminNumber::factory()->create(['phone' => '16505553333']);
        $admin = $this->conversationTagged([], ['phone' => '16505553333', 'wa_phone' => '16505553333']);
        $flagged = $this->conversationTagged([], ['is_admin_chat' => true]);
        $customer = $this->conversationTagged();
        foreach ([$admin, $flagged, $customer] as $c) {
            $this->say($c, 'تقرير المبيعات اليومي');
        }
        $manager = $this->staffWith('manager', ['whatsapp.view', 'whatsapp.view_all']);
        $owner = $this->staffWith('owner', ['whatsapp.view', 'whatsapp.view_all', 'whatsapp.admin_chats']);

        $hidden = $this->search($manager, ['q' => 'المبيعات'])->assertOk();
        $this->assertSame([$customer->id], array_column($hidden->json('hits'), 'conversation_id'));
        $this->assertSame(1, $hidden->json('total'), 'العدد لا يكشف نتائج مخفية');

        $this->assertSame(3, $this->search($owner, ['q' => 'المبيعات'])->json('total'));
    }

    public function test_paging_never_reaches_beyond_the_fence(): void
    {
        $umrah = $this->staffWith('umrah-desk', ['whatsapp.view'], ['umrah']);
        $mine = $this->conversationTagged(['umrah']);
        $other = $this->conversationTagged(['visa']);
        foreach (range(1, 4) as $i) {
            $this->say($other, "حجز فندق رقم {$i}");
            $this->say($mine, "حجز فندق للعميل {$i}");
        }

        $seen = [];
        foreach ([0, 2, 4] as $offset) {
            $page = $this->search($umrah, ['q' => 'فندق', 'limit' => 2, 'offset' => $offset])->assertOk();
            $this->assertSame(4, $page->json('total'));
            $seen = [...$seen, ...array_column($page->json('hits'), 'conversation_id')];
        }
        $this->assertSame(array_fill(0, 4, $mine->id), $seen);
    }

    public function test_a_conversation_outside_the_fence_is_not_found(): void
    {
        $umrah = $this->staffWith('umrah-desk', ['whatsapp.view'], ['umrah']);
        $visa = $this->conversationTagged(['visa']);
        $this->say($visa, 'مرحبا');

        $this->search($umrah, ['q' => 'مرحبا', 'conversation_id' => $visa->id])->assertNotFound();
        $this->search($umrah, ['q' => 'مرحبا', 'conversation_id' => 999999])->assertNotFound();
    }

    public function test_merged_conversations_and_reactions_are_left_out(): void
    {
        $manager = $this->staffWith('manager', ['whatsapp.view', 'whatsapp.view_all']);
        $merged = $this->conversationTagged([], ['status' => 'merged']);
        $live = $this->conversationTagged();
        $this->say($merged, 'كلمة مدموجة');
        $hit = $this->say($live, 'كلمة حية');
        $this->say($live, 'كلمة', ['msg_type' => 'reaction']);

        $this->assertSame([$hit->id], array_column($this->search($manager, ['q' => 'كلمة'])->json('hits'), 'message_id'));
    }

    public function test_results_carry_no_phone_and_a_name_without_it(): void
    {
        $manager = $this->staffWith('manager', ['whatsapp.view', 'whatsapp.view_all']);
        $c = $this->conversationTagged([], ['phone' => '16505551234', 'wa_phone' => '16505551234', 'display_name' => null, 'wa_profile_name' => null]);
        $this->say($c, 'رقمي 16505551234 للاتصال');

        $response = $this->search($manager, ['q' => 'للاتصال'])->assertOk();

        $this->assertSame("محادثة #{$c->id}", $response->json('hits.0.conversation_name'));
        $this->assertSame(['message_id', 'conversation_id', 'conversation_name', 'at', 'direction', 'source', 'msg_type', 'segments'], array_keys($response->json('hits.0')));
        $this->assertStringNotContainsString('+16505551234', $response->getContent());
    }

    public function test_the_flags_open_search_to_managers_first_then_to_everyone(): void
    {
        $agent = $this->staffWith('agent', ['whatsapp.view'], ['umrah']);
        $manager = $this->staffWith('manager', ['whatsapp.view', 'whatsapp.view_all']);
        $this->say($this->conversationTagged(['umrah']), 'سؤال عن الرحلة');

        config(['features.env.message_search' => false, 'features.env.message_search_all' => false]);
        $this->search($manager, ['q' => 'الرحلة'])->assertNotFound();

        config(['features.env.message_search' => true]);
        $this->search($manager, ['q' => 'الرحلة'])->assertOk();
        $this->search($agent, ['q' => 'الرحلة'])->assertNotFound();
        $this->actingAs($agent)->get('/whatsapp/inbox')->assertInertia(fn ($p) => $p->where('messageSearch', false));
        $this->actingAs($manager)->get('/whatsapp/inbox')->assertInertia(fn ($p) => $p->where('messageSearch', true));

        config(['features.env.message_search_all' => true]);
        $this->assertSame(1, $this->search($agent, ['q' => 'الرحلة'])->json('total'));

        $outsider = User::factory()->withPermissions([], 'outsider')->create();
        $this->search($outsider, ['q' => 'الرحلة'])->assertForbidden();
    }
}
