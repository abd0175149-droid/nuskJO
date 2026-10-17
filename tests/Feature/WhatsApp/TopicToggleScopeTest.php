<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * الوسوم والتوكيل لا توسّع رؤية صاحب الطلب: لا وسم على محادثة لا يراها، ولا توكيل له بإضافة وسم،
 * وإزالة وسم مختص آخر لمن يوزّع المحادثات، والتوكيل لمن يستطيع فتح المحادثة فقط.
 */
#[Group('INV-17')]
class TopicToggleScopeTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    private function topic(User $user, int $conversationId, string $topic, bool $attach)
    {
        return $this->actingAs($user)->postJson("/api/whatsapp/conversations/{$conversationId}/topic", compact('topic', 'attach'));
    }

    private function topicsOf(int $conversationId): array
    {
        return DB::table('wa_conversation_topics')->where('conversation_id', $conversationId)->orderBy('topic')->pluck('topic')->all();
    }

    public function test_tagging_a_hidden_conversation_with_your_own_topic_is_not_found(): void
    {
        $specialist = $this->staffWith('umrah-desk', ['whatsapp.view', 'whatsapp.reply'], ['umrah']);
        $hidden = $this->conversationTagged();

        $this->topic($specialist, $hidden->id, 'umrah', true)->assertNotFound();

        $this->assertSame([], $this->topicsOf($hidden->id));
    }

    public function test_tagging_a_visible_conversation_does_not_assign_it_to_the_requester(): void
    {
        $specialist = $this->staffWith('umrah-desk', ['whatsapp.view', 'whatsapp.reply'], ['umrah']);
        $visaLead = $this->staffWith('visa-desk', ['whatsapp.view'], ['visa'], primary: true);
        $conversation = $this->conversationTagged(['umrah']);

        $this->topic($specialist, $conversation->id, 'visa', true)->assertOk()
            ->assertJsonPath('topics.1.key', 'visa');

        $this->assertSame(['umrah', 'visa'], $this->topicsOf($conversation->id));
        $this->assertSame($visaLead->id, $conversation->fresh()->assigned_to);
    }

    public function test_removing_a_colleagues_topic_needs_the_assign_permission(): void
    {
        $specialist = $this->staffWith('umrah-desk', ['whatsapp.view', 'whatsapp.reply'], ['umrah']);
        $conversation = $this->conversationTagged(['umrah', 'visa']);

        $this->topic($specialist, $conversation->id, 'visa', false)->assertForbidden();
        $this->assertSame(['umrah', 'visa'], $this->topicsOf($conversation->id));

        $this->topic($specialist, $conversation->id, 'umrah', false)->assertOk();
        $this->assertSame(['visa'], $this->topicsOf($conversation->id));
    }

    public function test_dispatcher_may_remove_any_topic(): void
    {
        $dispatcher = $this->staffWith('dispatch', ['whatsapp.view', 'whatsapp.reply', 'whatsapp.assign'], ['umrah']);
        $conversation = $this->conversationTagged(['umrah', 'visa']);

        $this->topic($dispatcher, $conversation->id, 'visa', false)->assertOk();

        $this->assertSame(['umrah'], $this->topicsOf($conversation->id));
    }

    public function test_assign_only_to_active_users_who_can_open_the_inbox(): void
    {
        $manager = $this->staffWith('manager', ['whatsapp.view', 'whatsapp.view_all', 'whatsapp.assign']);
        $conversation = $this->conversationTagged();
        $inactive = User::factory()->withPermissions(['whatsapp.view'], 'agent')->inactive()->create();
        $noInbox = User::factory()->withPermissions(['quotes.view'], 'accounts')->create();
        $agent = User::factory()->withPermissions(['whatsapp.view'], 'agent')->create();

        foreach ([$inactive, $noInbox] as $user) {
            $this->actingAs($manager)->postJson("/api/whatsapp/conversations/{$conversation->id}/assign", ['user_id' => $user->id])
                ->assertStatus(422)->assertJsonValidationErrors('user_id');
            $this->assertNull($conversation->fresh()->assigned_to);
        }

        $this->actingAs($manager)->postJson("/api/whatsapp/conversations/{$conversation->id}/assign", ['user_id' => $agent->id])
            ->assertOk()->assertJsonPath('assigned_to', $agent->id);
    }

    public function test_assign_list_on_the_inbox_offers_only_assignable_users(): void
    {
        $manager = $this->staffWith('manager', ['whatsapp.view', 'whatsapp.view_all', 'whatsapp.assign']);
        $agent = User::factory()->withPermissions(['whatsapp.view'], 'agent')->create();
        $noInbox = User::factory()->withPermissions(['quotes.view'], 'accounts')->create();

        $this->actingAs($manager)->get('/whatsapp/inbox')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('staff', fn ($staff) => collect($staff)->pluck('id')->contains($agent->id)
                    && !collect($staff)->pluck('id')->contains($noInbox->id)));
    }
}
