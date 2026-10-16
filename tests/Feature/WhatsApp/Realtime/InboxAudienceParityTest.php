<?php

namespace Tests\Feature\WhatsApp\Realtime;

use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Services\WhatsApp\Realtime\InboxAudience;
use App\Services\WhatsApp\TopicRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * تكافؤ جمهور البث مع سياج E04 (E28): لكل محادثة، من يصله حدثها هو بالضبط النشطون الذين يملكون whatsapp.view
 * ويراها لهم TopicRouter::canSee. أي اختلاف يُفشل الاختبار باسم الحالة والمستخدم.
 */
class InboxAudienceParityTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, WaConversation> */
    private array $conversations = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = [
            'admin' => User::factory()->admin()->create(),
            'view_all' => $this->staffWith('supervisor', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']),
            'umrah' => $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']),
            'visa' => $this->staffWith('visa-desk', self::INBOX_PERMISSIONS, ['visa']),
            'assignee' => $this->staffWith('desk', self::INBOX_PERMISSIONS),
            'disabled' => $this->staffWith('umrah-desk-2', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all'], ['umrah']),
            'admin_chats' => $this->staffWith('reports', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all', 'whatsapp.admin_chats']),
            'no_view' => $this->staffWith('accounts', ['clients.view'], ['umrah']),
        ];
        $this->users['disabled']->forceFill(['is_active' => false])->save();

        $this->conversations = [
            'untagged' => $this->conversationTagged(),
            'umrah' => $this->conversationTagged(['umrah']),
            'two_tags' => $this->conversationTagged(['umrah', 'visa']),
            'assigned' => $this->conversationTagged([], ['assigned_to' => $this->users['assignee']->id]),
            'assigned_and_tagged' => $this->conversationTagged(['visa'], ['assigned_to' => $this->users['assignee']->id]),
            'phoneless' => $this->conversationTagged(['umrah'], ['phone' => null, 'wa_phone' => null, 'wa_user_id' => 'US.13491208655302741918']),
        ];

        // محادثة رقم إدارة فعّال (العلم اللاصق)، ومحادثة رقم إدارة معطّل، ومحادثة رقمها في الجدول بلا العلم
        $admin = $this->conversationTagged(['umrah']);
        WaAdminNumber::factory()->create(['phone' => $admin->phone, 'is_active' => true]);
        $disabledNumber = $this->conversationTagged(['visa'], ['assigned_to' => $this->users['assignee']->id]);
        WaAdminNumber::factory()->create(['phone' => $disabledNumber->phone, 'is_active' => false]);
        $phoneOnly = $this->conversationTagged(['umrah']);
        DB::table('wa_admin_numbers')->insert(['phone' => $phoneOnly->phone, 'name' => 'رقم', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->conversations += ['admin_chat' => $admin->fresh(), 'admin_chat_disabled_number' => $disabledNumber->fresh(), 'admin_number_without_flag' => $phoneOnly->fresh()];
    }

    /** @return list<string> أسماء المستخدمين الذين يرون المحادثة بحسب السياج */
    private function fenced(WaConversation $conversation): array
    {
        $names = [];
        foreach ($this->users as $name => $user) {
            $user = $user->fresh();
            if ($user->is_active && $user->can('whatsapp.view') && TopicRouter::canSee($conversation, $user)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function names(array $ids): array
    {
        $byId = array_flip(array_map(fn (User $u) => $u->id, $this->users));
        $names = array_map(fn (int $id) => $byId[$id] ?? "unknown:{$id}", $ids);
        sort($names);

        return $names;
    }

    public function test_the_audience_of_every_conversation_matches_the_fence(): void
    {
        foreach ($this->conversations as $case => $conversation) {
            $expected = $this->fenced($conversation);
            sort($expected);

            $this->assertSame($expected, $this->names(InboxAudience::for($conversation)), "الحالة: {$case}");
        }
    }

    public function test_the_batch_form_matches_the_single_form(): void
    {
        $batch = InboxAudience::forMany($this->conversations);

        foreach ($this->conversations as $case => $conversation) {
            $this->assertSame(InboxAudience::for($conversation), $batch[$conversation->id], "الحالة: {$case}");
        }
    }

    public function test_the_expected_people_see_the_key_cases(): void
    {
        $this->assertSame(['admin', 'admin_chats', 'umrah', 'view_all'], $this->names(InboxAudience::for($this->conversations['umrah'])));
        $this->assertSame(['admin', 'admin_chats', 'assignee', 'view_all', 'visa'], $this->names(InboxAudience::for($this->conversations['assigned_and_tagged'])));
        $this->assertSame(['admin', 'admin_chats'], $this->names(InboxAudience::for($this->conversations['admin_chat'])));
        $this->assertSame(['admin', 'admin_chats'], $this->names(InboxAudience::for($this->conversations['admin_chat_disabled_number'])));
        $this->assertSame(['admin', 'admin_chats', 'view_all'], $this->names(InboxAudience::for($this->conversations['untagged'])));
        // عميل باسم مستخدم بلا رقم ليس رقم إدارة: يراه مختص وسمه
        $this->assertSame(['admin', 'admin_chats', 'umrah', 'view_all'], $this->names(InboxAudience::for($this->conversations['phoneless'])));
    }

    public function test_a_customer_without_a_number_stays_reachable_by_the_topic_specialist(): void
    {
        $conversation = $this->conversations['phoneless'];

        $this->actingAs($this->users['umrah'])->getJson("/api/whatsapp/conversations/{$conversation->id}/messages")->assertOk();
        $this->actingAs($this->users['visa'])->getJson("/api/whatsapp/conversations/{$conversation->id}/messages")->assertNotFound();
        $this->actingAs($this->users['umrah'])->getJson("/api/whatsapp/conversations/{$this->conversations['admin_number_without_flag']->id}/messages")->assertNotFound();
    }

    public function test_moving_a_specialty_or_disabling_a_user_changes_the_audience_at_once(): void
    {
        $conversation = $this->conversations['umrah'];
        DB::table('wa_topic_users')->where('user_id', $this->users['umrah']->id)->delete();
        $this->users['view_all']->forceFill(['is_active' => false])->save();

        $this->assertSame(['admin', 'admin_chats'], $this->names(InboxAudience::for($conversation)));
    }
}
