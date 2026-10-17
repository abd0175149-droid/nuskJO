<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * تفاعل الموظف (E33، العلم wa_staff_reactions، مراجعة C008): نوع reaction بلا context، من قائمة بيضاء، على رسالة عميل
 * عمرها 30 يوماً أو أقل، عبر dispatch بأقفاله كلها. إقرار لا رد: لا يُسكت البوت ولا يخفي شارة التدخّل ولا يغيّر المعاينة
 * ولا العلامة، والبوت ما زال يجيب رسالة العميل المعلّقة.
 */
class ReactionOutboundTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $staff;

    private WaConversation $conv;

    private WaMessage $in;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.wa_staff_reactions' => true]);
        $this->staff = $this->staffWith('desk', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $this->conv = WaConversation::factory()->windowOpen()->create([
            'needs_attention' => true, 'attention_reason' => 'handoff', 'unread_count' => 3, 'last_message_preview' => 'هذا جواز السفر', 'bot_answered_in_id' => null,
        ]);
        $this->in = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.IN.PASS', 'body' => 'هذا جواز السفر']);
    }

    private function react(WaMessage $target, ?string $emoji, ?WaConversation $conv = null)
    {
        $conv ??= $this->conv;

        return $this->actingAs($this->staff)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/whatsapp/conversations/{$conv->id}/messages/{$target->id}/react", ['emoji' => $emoji]);
    }

    private function snapshot(): array
    {
        $c = $this->conv->fresh();

        return [$c->bot_paused_until, $c->needs_attention, $c->attention_reason, $c->unread_count, $c->bot_answered_in_id,
            $c->last_message_preview, (string) $c->last_outbound_at, (string) $c->last_message_at];
    }

    public static function ledgerModes(): array
    {
        return ['legacy path' => [false], 'send ledger' => [true]];
    }

    #[DataProvider('ledgerModes')]
    public function test_a_reaction_is_sent_without_context_and_changes_nothing_of_a_reply(bool $ledger): void
    {
        config(['features.env.send_ledger' => $ledger, 'audit.extended' => true]);
        $this->fakeGraph();
        $before = $this->snapshot();

        $this->react($this->in, '✅')->assertOk()->assertJson(['ok' => true]);

        $request = collect(Http::recorded())->map(fn ($p) => $p[0])->sole();
        $this->assertSame('reaction', $request['type']);
        $this->assertSame(['message_id' => 'wamid.IN.PASS', 'emoji' => '✅'], $request['reaction']);
        $this->assertArrayNotHasKey('context', $request->data());
        $this->assertSame($before, $this->snapshot(), 'لا إيقاف للبوت ولا مسح لشارة التدخّل ولا معاينة ولا علامة');
        $this->assertSame('✅', data_get($this->in->fresh()->payload, 'reactions.business.emoji'));
        $this->assertSame($this->staff->id, data_get($this->in->fresh()->payload, 'reactions.business.staff_id'));
        $row = WaMessage::where('direction', 'out')->sole();
        $this->assertSame(['reaction', 'staff', '✅'], [$row->msg_type, $row->source, $row->body]);
        $this->assertSame(1, AuditLog::where('action', 'wa.reaction_send')->where('entity_id', $this->in->id)->count());
    }

    public function test_removing_a_reaction_sends_an_empty_emoji_and_clears_the_badge(): void
    {
        $this->fakeGraph();
        $this->react($this->in, '✅')->assertOk();

        $this->react($this->in, '')->assertOk();

        $this->assertSame('', collect(Http::recorded())->map(fn ($p) => $p[0])->last()['reaction']['emoji']);
        $this->assertNull(data_get($this->in->fresh()->payload, 'reactions'));
        $this->assertSame('↩️ أُزيل التفاعل', WaMessage::where('direction', 'out')->orderByDesc('id')->first()->body);
    }

    public function test_an_emoji_outside_the_list_is_refused_without_http(): void
    {
        $this->fakeGraph();

        $this->react($this->in, '😡')->assertStatus(422)->assertJson(['code' => 'BAD_REACTION']);

        Http::assertNothingSent();
    }

    public function test_old_outbound_or_reaction_targets_are_refused_without_http(): void
    {
        $this->fakeGraph();
        $old = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'created_at' => now()->subDays(31)]);
        $out = WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id]);
        $reaction = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'reaction', 'body' => '👍']);

        $this->react($old, '✅')->assertStatus(422)->assertJson(['code' => 'REACTION_TOO_OLD']);
        $this->react($out, '✅')->assertStatus(422)->assertJson(['code' => 'BAD_REACTION_TARGET']);
        $this->react($reaction, '✅')->assertStatus(422)->assertJson(['code' => 'BAD_REACTION_TARGET']);
        Http::assertNothingSent();
    }

    public function test_a_closed_window_is_refused_and_the_flag_gates_the_route(): void
    {
        $this->fakeGraph();
        $this->conv->update(['last_inbound_at' => now()->subDays(2)]);

        $this->react($this->in, '✅')->assertStatus(422)->assertJson(['code' => 'WINDOW_EXPIRED']);

        config(['features.env.wa_staff_reactions' => false]);
        $this->conv->update(['last_inbound_at' => now()->subHour()]);
        $this->react($this->in, '✅')->assertStatus(422)->assertJson(['code' => 'FEATURE_OFF']);
        Http::assertNothingSent();
    }

    public function test_a_message_from_another_conversation_or_out_of_scope_is_not_found(): void
    {
        $this->fakeGraph();
        $other = WaMessage::factory()->inbound()->create();
        $this->react($other, '✅')->assertNotFound();

        $this->staff = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $this->react($this->in, '✅')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_meta_131009_is_explained_in_arabic(): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131009, 'message' => '(#131009) Parameter value is not valid']], 400)]);

        $this->react($this->in, '✅')->assertStatus(422)->assertJson(['error' => 'تعذّر التفاعل: الرسالة قديمة أو محذوفة.']);
    }

    public static function watermarkModes(): array
    {
        return ['watermark off (legacy gate)' => [false], 'watermark on' => [true]];
    }

    /** مراجعة C008: رسالة عميل معلّقة ثم تفاعل موظف، والبوت ما زال يجيبها */
    #[DataProvider('watermarkModes')]
    public function test_a_staff_reaction_does_not_swallow_a_pending_customer_message(bool $watermark): void
    {
        $this->configureChannel(['reliability' => ['watermark' => $watermark]]);
        $this->conv->update(['needs_attention' => false, 'attention_reason' => null]);
        $this->fakeGraph();
        $this->fakeGemini('أهلاً، وصلنا الجواز وسيتابع معك الموظف');

        $this->react($this->in, '✅')->assertOk();

        $this->assertTrue(BotEngine::willReply($this->conv->fresh()));
        $result = BotEngine::reply($this->conv->fresh());
        $this->assertTrue($result['sent'], (string) ($result['reason'] ?? ''));
        $this->assertSame(['أهلاً، وصلنا الجواز وسيتابع معك الموظف'], WaMessage::where('source', 'bot')->pluck('body')->all());
        $this->assertTrue(collect(Http::recorded())->contains(fn ($p) => $p[0] instanceof Request && ($p[0]['type'] ?? null) === 'text'));
    }
}
