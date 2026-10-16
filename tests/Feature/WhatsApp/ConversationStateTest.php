<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaConversationPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * متابعة الصندوق (E17): الإغلاق مع إعادة الفتح عند أي وارد، والتأجيل المقيّد بالنافذة وإيقاظه، والتثبيت الشخصي.
 * القاعدة: ما يحتاج تدخّلاً لا يُخفى أبداً.
 */
class ConversationStateTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const PHONE = '16505551234';

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        config(['features.env.inbox_followup' => true, 'audit.extended' => true]);
        $this->staff = $this->staffWith('desk', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
    }

    private function conv(array $attrs = []): WaConversation
    {
        return WaConversation::factory()->windowOpen()->create(['last_message_at' => now()] + $attrs);
    }

    private function listIds(string $filter = 'all'): array
    {
        return array_column($this->actingAs($this->staff)->getJson('/api/whatsapp/conversations?filter='.$filter)->assertOk()->json('conversations'), 'id');
    }

    private function inbound(array $message)
    {
        $payload = json_decode(file_get_contents($this->fixturePath('text.json')), true);
        data_set($payload, 'entry.0.changes.0.value.messages.0', $message + ['from' => self::PHONE, 'id' => 'wamid.T'.uniqid(), 'timestamp' => (string) now()->timestamp]);

        return $this->postRawWebhook(json_encode($payload))->assertOk();
    }

    public function test_closed_conversation_leaves_all_and_appears_under_closed(): void
    {
        $c = $this->conv();
        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$c->id}/close")->assertOk()->assertJsonPath('closed', true);

        $this->assertNotContains($c->id, $this->listIds());
        $this->assertContains($c->id, $this->listIds('closed'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'wa.conv_close', 'entity_id' => $c->id]);

        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$c->id}/reopen")->assertOk()->assertJsonPath('closed', false);
        $this->assertContains($c->id, $this->listIds());
        $this->assertDatabaseHas('audit_logs', ['action' => 'wa.conv_reopen', 'entity_id' => $c->id]);
    }

    public function test_any_inbound_reopens_and_clears_the_snooze_but_a_reaction_does_not(): void
    {
        $c = $this->conv(['phone' => self::PHONE, 'wa_phone' => self::PHONE]);
        $c->forceFill(['status' => 'closed', 'closed_at' => now(), 'snoozed_until' => now()->addHour()])->save();

        $this->inbound(['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.X', 'emoji' => '👍']]);
        $this->assertSame('closed', $c->fresh()->status);

        $this->inbound(['type' => 'text', 'text' => ['body' => 'مرحبا']]);
        $fresh = $c->fresh();
        $this->assertSame('open', $fresh->status);
        $this->assertNull($fresh->closed_at);
        $this->assertNull($fresh->snoozed_until);
    }

    public function test_a_merged_conversation_is_never_reopened(): void
    {
        $c = $this->conv(['status' => 'merged']);
        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$c->id}/reopen")->assertStatus(422);
        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$c->id}/close")->assertStatus(422);
        $this->assertSame('merged', $c->fresh()->status);
    }

    public function test_a_conversation_needing_attention_stays_visible_when_closed_or_snoozed(): void
    {
        $closed = $this->conv(['needs_attention' => true, 'status' => 'closed']);
        $snoozed = $this->conv(['needs_attention' => true]);
        $snoozed->forceFill(['snoozed_until' => now()->addHour()])->save();
        $quiet = $this->conv();
        $quiet->forceFill(['snoozed_until' => now()->addHour()])->save();

        $all = $this->listIds();
        $this->assertContains($closed->id, $all);
        $this->assertContains($snoozed->id, $all);
        $this->assertNotContains($quiet->id, $all);
        $this->assertEqualsCanonicalizing([$snoozed->id, $quiet->id], $this->listIds('snoozed'));
    }

    public function test_snooze_is_bounded_by_the_reply_window(): void
    {
        $c = $this->conv(['last_inbound_at' => now()->subHours(20)]);
        $url = "/api/whatsapp/conversations/{$c->id}/snooze";

        // النافذة تُغلق بعد 4 ساعات: أبعد موعد بعد 3:45
        $this->actingAs($this->staff)->postJson($url, ['until' => now()->addHours(4)->toIso8601String()])->assertStatus(422);
        $this->actingAs($this->staff)->postJson($url, ['minutes' => 5])->assertStatus(422);
        $this->actingAs($this->staff)->postJson($url, ['until' => now()->addHours(3)->toIso8601String()])->assertOk()->assertJsonPath('snoozed_until', fn ($v) => $v !== null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'wa.conv_snooze', 'entity_id' => $c->id]);

        $this->actingAs($this->staff)->deleteJson($url)->assertOk()->assertJsonPath('snoozed_until', null);

        $closedWindow = $this->conv(['last_inbound_at' => now()->subDays(2)]);
        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$closedWindow->id}/snooze", ['minutes' => 60])
            ->assertStatus(422)->assertJsonPath('error', 'لا يمكن التأجيل بعد إغلاق نافذة الرد — لا قوالب لدينا');
    }

    public function test_snooze_wake_raises_attention_once_and_notifies_who_snoozed(): void
    {
        $c = $this->conv();
        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$c->id}/snooze", ['minutes' => 30])->assertOk();

        $this->artisan('whatsapp:snooze-wake')->assertSuccessful();
        $this->assertFalse((bool) $c->fresh()->needs_attention);

        $this->travel(31)->minutes();
        $this->artisan('whatsapp:snooze-wake')->assertSuccessful();
        $this->artisan('whatsapp:snooze-wake')->assertSuccessful();

        $fresh = $c->fresh();
        $this->assertTrue((bool) $fresh->needs_attention);
        $this->assertSame('snooze_due', $fresh->attention_reason);
        $this->assertNull($fresh->snoozed_until);
        $notes = Notification::where('user_id', $this->staff->id)->get();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('حان موعد متابعة', $notes->first()->body);
        $this->assertStringNotContainsString((string) $c->phone, $notes->first()->body);
    }

    public function test_pins_are_personal_capped_and_listed_first(): void
    {
        $old = $this->conv(['last_message_at' => now()->subDay()]);
        $this->conv(['last_message_at' => now()]);
        $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$old->id}/pin")->assertOk()->assertJsonPath('pinned', true);

        $rows = $this->actingAs($this->staff)->getJson('/api/whatsapp/conversations')->json('conversations');
        $this->assertSame($old->id, $rows[0]['id']);
        $this->assertTrue($rows[0]['pinned']);
        $this->assertSame([$old->id], $this->listIds('pinned'));

        // تثبيت موظف لا يظهر لغيره
        $other = $this->staffWith('desk2', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $first = $this->actingAs($other)->getJson('/api/whatsapp/conversations')->json('conversations.0');
        $this->assertNotSame($old->id, $first['id']);

        foreach (range(1, 4) as $i) {
            $this->actingAs($this->staff)->postJson('/api/whatsapp/conversations/'.$this->conv()->id.'/pin')->assertOk();
        }
        $this->actingAs($this->staff)->postJson('/api/whatsapp/conversations/'.$this->conv()->id.'/pin')->assertStatus(422);
        $this->assertSame(5, WaConversationPin::where('user_id', $this->staff->id)->count());

        $this->actingAs($this->staff)->deleteJson("/api/whatsapp/conversations/{$old->id}/pin")->assertOk()->assertJsonPath('pinned', false);
    }

    public function test_a_pin_does_not_widen_what_staff_can_see(): void
    {
        $specialist = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $hidden = $this->conversationTagged(['visa']);
        DB::table('wa_conversation_pins')->insert(['user_id' => $specialist->id, 'conversation_id' => $hidden->id, 'created_at' => now()]);

        $ids = array_column($this->actingAs($specialist)->getJson('/api/whatsapp/conversations')->json('conversations'), 'id');
        $this->assertNotContains($hidden->id, $ids);
        $ids = array_column($this->actingAs($specialist)->getJson('/api/whatsapp/conversations?filter=pinned')->json('conversations'), 'id');
        $this->assertSame([], $ids);
    }

    public function test_with_the_flag_off_the_list_ignores_closed_status(): void
    {
        config(['features.env.inbox_followup' => false]);
        $c = $this->conv(['status' => 'closed']);

        $this->assertContains($c->id, $this->listIds());
        $this->assertArrayNotHasKey('closed', $this->actingAs($this->staff)->getJson('/api/whatsapp/conversations')->json('conversations.0'));
    }
}
