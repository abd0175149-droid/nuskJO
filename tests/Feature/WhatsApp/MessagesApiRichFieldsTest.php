<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * حقول الخيط الغنية (E33): الاقتباس بمعرّفنا من المحادثة نفسها وحدها، والتفاعلات مجمّعة على أهدافها (صف التفاعل الذي
 * هدفه في الصفحة لا يُعاد فقاعة، واليتيم orphan)، والتصحيح، وما يُسمح به لكل رسالة بحسب الأعلام. لا wamid ولا payload.
 */
class MessagesApiRichFieldsTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    private User $staff;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = $this->staffWith('desk', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $this->conv = WaConversation::factory()->windowOpen()->create();
    }

    private function thread(): array
    {
        return $this->actingAs($this->staff)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages")->assertOk()->json();
    }

    private function byBody(array $thread): array
    {
        return collect($thread['messages'])->keyBy('body')->all();
    }

    public function test_reply_ref_is_resolved_inside_the_same_conversation_only(): void
    {
        $mine = WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.MINE', 'body' => 'عرض عمرة رمضان بسعر خاص']);
        WaMessage::factory()->outbound()->create(['wamid' => 'wamid.THEIRS', 'body' => 'نص من محادثة أخرى']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'reply_to_wamid' => 'wamid.MINE', 'body' => 'هل ما زال متاحاً؟']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'reply_to_wamid' => 'wamid.THEIRS', 'body' => 'وهذا؟']);

        $thread = $this->thread();
        $m = $this->byBody($thread);

        $this->assertSame(['id' => $mine->id, 'snippet' => 'عرض عمرة رمضان بسعر خاص', 'direction' => 'out'], $m['هل ما زال متاحاً؟']['reply_ref']);
        $this->assertArrayNotHasKey('reply_ref', $m['وهذا؟']);
        $this->assertStringNotContainsString('نص من محادثة أخرى', json_encode($thread, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('wamid.', json_encode($thread));
        $this->assertArrayNotHasKey('payload', $m['وهذا؟']);
    }

    public function test_reactions_are_grouped_on_their_targets_and_orphans_are_flagged(): void
    {
        $target = WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.CARD', 'body' => 'بطاقة العرض',
            'payload' => ['reactions' => ['customer' => ['emoji' => '🙏', 'ts' => 100], 'business' => ['emoji' => '✅', 'staff_id' => 1]]]]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'reaction', 'body' => '🙏',
            'payload' => ['reaction' => ['message_id' => 'wamid.CARD', 'emoji' => '🙏'], '_reaction_to' => 'wamid.CARD']]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'reaction', 'body' => '❤️',
            'payload' => ['reaction' => ['message_id' => 'wamid.GONE', 'emoji' => '❤️'], '_reaction_to' => 'wamid.GONE']]);

        $messages = $this->thread()['messages'];

        $this->assertSame(['بطاقة العرض', '❤️'], array_column($messages, 'body'), 'صف التفاعل الذي هدفه في الصفحة ليس فقاعة');
        $this->assertSame(['customer' => '🙏', 'business' => '✅'], $messages[0]['reactions']);
        $this->assertTrue($messages[1]['orphan']);
        $this->assertSame($target->id, $messages[0]['id']);
    }

    public function test_a_polled_reaction_points_to_its_loaded_target(): void
    {
        $target = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.IN', 'body' => 'هذا الجواز',
            'payload' => ['reactions' => ['business' => ['emoji' => '✅', 'staff_id' => 1]]]]);
        $reaction = WaMessage::factory()->create(['conversation_id' => $this->conv->id, 'direction' => 'out', 'source' => 'staff', 'msg_type' => 'reaction',
            'body' => '✅', 'status' => 'sent', 'payload' => ['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.IN', 'emoji' => '✅']]]);

        $polled = $this->actingAs($this->staff)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages?after={$target->id}")->assertOk()->json('messages');

        $this->assertSame([[$reaction->id, $target->id]], array_map(fn ($m) => [$m['id'], $m['target_id']], $polled));
        $this->assertSame(['business' => '✅'], $polled[0]['target_reactions']);
    }

    public function test_permissions_per_message_follow_the_flags(): void
    {
        $in = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.A', 'body' => 'وارد']);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.B', 'body' => 'وارد قديم', 'created_at' => now()->subDays(31)]);
        WaMessage::factory()->fromStaff($this->staff)->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.C', 'body' => 'صادر']);
        WaMessage::factory()->fromStaff($this->staff)->create(['conversation_id' => $this->conv->id, 'wamid' => null, 'status' => 'failed', 'body' => 'فاشل']);
        $flags = fn (array $m) => [$m['can_quote'], $m['can_react'], $m['can_correct']];

        $off = $this->byBody($this->thread());
        $this->assertSame([false, false, false], $flags($off['وارد']));
        $this->assertSame(['quote' => false, 'react' => false, 'preview' => false, 'share' => false], $this->thread()['conversation']['features']);

        config(['features.env.wa_reply_quote' => true, 'features.env.wa_staff_reactions' => true]);
        $on = $this->byBody($this->thread());
        $this->assertSame([true, true, false], $flags($on['وارد']));
        $this->assertSame([true, false, false], $flags($on['وارد قديم']), 'أقدم من 30 يوماً');
        $this->assertSame([true, false, true], $flags($on['صادر']));
        $this->assertSame([false, false, false], $flags($on['فاشل']));
        $this->assertSame($in->id, $on['وارد']['id']);
    }

    public function test_corrections_link_both_ways(): void
    {
        $orig = WaMessage::factory()->fromStaff($this->staff)->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.O', 'body' => 'السعر 150']);
        $fix = WaMessage::factory()->fromStaff($this->staff)->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.F', 'body' => 'تصحيح: 165',
            'reply_to_wamid' => 'wamid.O', 'payload' => ['_meta' => ['corrects_message_id' => $orig->id]]]);
        $orig->update(['payload' => ['_corrected_by' => [['message_id' => $fix->id, 'at' => now()->toIso8601String(), 'staff_id' => $this->staff->id]]]]);

        $m = $this->byBody($this->thread());

        $this->assertSame([$fix->id], $m['السعر 150']['corrected_by']);
        $this->assertSame($orig->id, $m['تصحيح: 165']['corrects_id']);
        $this->assertSame('السعر 150', $m['تصحيح: 165']['reply_ref']['snippet']);
    }
}
