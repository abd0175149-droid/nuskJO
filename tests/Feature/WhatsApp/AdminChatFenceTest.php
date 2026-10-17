<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * محادثات أرقام الإدارة (تقارير وإيرادات) لمن يملك whatsapp.admin_chats ولدور admin وحدهما،
 * ولو وُسمت باختصاص موظف أو وُكّلت له، ولو ملك المدير view_all. والحجب يبقى بعد حذف الرقم أو تعطيله.
 */
#[Group('INV-17')]
class AdminChatFenceTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaAdminNumber $number;

    private WaConversation $adminChat;

    private User $specialist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->fakeGraph();

        $this->specialist = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $this->number = WaAdminNumber::factory()->create();
        $this->adminChat = $this->conversationTagged(['umrah'], [
            'phone' => $this->number->phone, 'wa_phone' => $this->number->phone,
            'assigned_to' => $this->specialist->id, 'unread_count' => 5,
        ]);
    }

    private function manager(array $extra = []): User
    {
        return $this->staffWith('manager'.count($extra), [...self::INBOX_PERMISSIONS, 'whatsapp.view_all', ...$extra]);
    }

    private function assertHiddenFrom(User $user): void
    {
        $list = $this->actingAs($user)->getJson('/api/whatsapp/conversations')->assertOk();
        $this->assertNotContains($this->adminChat->id, array_column($list->json('conversations'), 'id'));
        $this->assertSame(0, $list->json('unread_total'));

        $this->actingAs($user)->getJson("/api/whatsapp/conversations/{$this->adminChat->id}/messages")->assertNotFound();
        $this->actingAs($user)->postJson("/api/whatsapp/conversations/{$this->adminChat->id}/send", ['body' => 'x'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_hidden_from_a_specialist_even_when_tagged_and_assigned(): void
    {
        $this->assertHiddenFrom($this->specialist);
    }

    public function test_hidden_from_a_manager_who_sees_all_other_conversations(): void
    {
        $this->assertHiddenFrom($this->manager());
    }

    public function test_visible_with_the_admin_chats_permission_and_to_the_admin_role(): void
    {
        foreach ([$this->manager(['whatsapp.admin_chats']), User::factory()->withPermissions([], 'admin')->create()] as $user) {
            $list = $this->actingAs($user)->getJson('/api/whatsapp/conversations')->assertOk();
            $row = collect($list->json('conversations'))->firstWhere('id', $this->adminChat->id);
            $this->assertNotNull($row);
            $this->assertTrue($row['is_admin']);
            $this->assertSame(5, $list->json('unread_total'));

            $this->actingAs($user)->getJson("/api/whatsapp/conversations/{$this->adminChat->id}/messages")
                ->assertOk()->assertJsonPath('conversation.is_admin', true);
        }
    }

    public function test_stays_hidden_after_the_number_is_deactivated_or_deleted(): void
    {
        $manager = $this->manager();

        $this->number->update(['is_active' => false]);
        $this->assertHiddenFrom($manager);

        $this->number->delete();
        $this->assertTrue($this->adminChat->fresh()->is_admin_chat);
        $this->assertHiddenFrom($manager);
    }

    public function test_changing_the_admin_number_flags_both_conversations(): void
    {
        $other = WaConversation::factory()->create(['phone' => '962790009999', 'wa_phone' => '962790009999']);

        $this->number->update(['phone' => '962790009999']);

        $this->assertTrue($this->adminChat->fresh()->is_admin_chat);
        $this->assertTrue($other->fresh()->is_admin_chat);
        $this->assertHiddenFrom($this->manager());
    }

    public function test_the_flag_cannot_be_cleared_through_mass_assignment(): void
    {
        $this->number->delete();

        $this->adminChat->fresh()->update(['is_admin_chat' => false, 'unread_count' => 0]);

        $this->assertTrue($this->adminChat->fresh()->is_admin_chat);
    }
}
