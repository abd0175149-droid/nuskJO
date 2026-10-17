<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaBlock;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\InboxSummary;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * البوابات (E31.4): لا شيء يُرسل لمحظور (BLOCKED بلا نداء ولا صف)، ولا يرد البوت، والوارد منه يُخزَّن ولا يصل البوت
 * ولا يزيد غير المقروء ويعلّم الحظر mismatch، ولا تذكير نافذة له، ولا يظهر في «تدخّل» ولا بطاقاته.
 */
class BlockGatesTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
    }

    private function blocked(array $attrs = []): WaConversation
    {
        $conv = WaConversation::factory()->windowOpen()->create($attrs + ['phone' => '16505551234', 'wa_phone' => '16505551234']);
        $conv->forceFill(['blocked_at' => now()->subMinutes(5), 'bot_enabled' => false])->save();
        WaBlock::create(['conversation_id' => $conv->id, 'phone' => '16505551234', 'reason_code' => 'spam', 'reason' => 'إزعاج متكرر طوال اليوم',
            'blocked_at' => now()->subMinutes(5), 'meta_state' => 'blocked']);

        return $conv->fresh();
    }

    public function test_nothing_is_sent_to_a_blocked_customer(): void
    {
        Http::fake();
        $conv = $this->blocked();

        foreach ([
            WhatsAppChannel::sendText($conv, 'مرحباً', 'staff', User::factory()->admin()->create()->id),
            WhatsAppChannel::sendText($conv, 'رد آلي', 'bot', sendKey: 'reply:t'),
            WhatsAppChannel::sendImage($conv, 'https://example.com/card.png', null, 'bot', sendKey: 'card:t'),
        ] as $r) {
            $this->assertSame([false, WhatsAppChannel::ERR_BLOCKED], [$r['ok'], $r['code']]);
        }
        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
    }

    public function test_the_bot_skips_a_blocked_conversation_without_raising_attention(): void
    {
        Http::fake();
        $conv = $this->blocked();
        $conv->forceFill(['bot_enabled' => true])->save();
        WaMessage::create(['conversation_id' => $conv->id, 'direction' => 'in', 'source' => 'customer', 'msg_type' => 'text', 'body' => 'مرحبا', 'wamid' => 'wamid.T1']);

        $r = BotEngine::reply($conv->fresh());

        $this->assertSame(['sent' => false, 'reason' => 'المحادثة محظورة'], $r);
        Http::assertNothingSent();
        $this->assertFalse($conv->fresh()->needs_attention);
    }

    public function test_inbound_from_a_blocked_number_is_stored_but_not_answered_and_flags_a_mismatch(): void
    {
        Http::fake();
        $this->captureLogs();
        $conv = $this->blocked(['unread_count' => 0]);
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/text.json')), true);

        $result = WhatsAppInbox::handleWebhook($payload);

        $this->assertSame([], $result->touched, 'لا يُجدول رد');
        $message = WaMessage::sole();
        $this->assertSame([$conv->id, 'in'], [$message->conversation_id, $message->direction]);
        $conv->refresh();
        $this->assertSame(0, (int) $conv->unread_count);
        $this->assertSame($message->id, (int) $conv->bot_answered_in_id, 'العلامة تتقدّم فلا يجيب عنه أحد لاحقاً');
        $this->assertSame('mismatch', WaBlock::sole()->meta_state);
        $this->assertLogged('warning', 'wa.block.inbound_while_blocked', ['conv_id' => $conv->id]);
        Http::assertNothingSent();
    }

    public function test_window_reminders_skip_a_blocked_conversation(): void
    {
        User::factory()->admin()->create();
        $conv = $this->blocked();
        $conv->forceFill(['needs_attention' => true, 'last_inbound_at' => now()->subHours(22)])->save();

        $this->artisan('whatsapp:window-reminders')->assertSuccessful();

        $this->assertSame(0, Notification::count());
    }

    public function test_a_blocked_conversation_is_kept_out_of_attention_lists_and_cards(): void
    {
        config(['features.env.wa_block' => true]);
        $holder = User::factory()->admin()->create();
        $viewer = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all'], 'viewer')->create();
        $blocked = $this->blocked();
        $blocked->forceFill(['needs_attention' => true])->save();
        $waiting = WaConversation::factory()->windowOpen()->create(['needs_attention' => true]);

        $ids = fn (User $u, string $filter) => array_column($this->actingAs($u)->getJson('/api/whatsapp/conversations?filter='.$filter)->assertOk()->json('conversations'), 'id');

        $this->assertSame([$waiting->id], $ids($holder, 'attention'));
        $this->assertEqualsCanonicalizing([$blocked->id, $waiting->id], $ids($holder, 'all'), 'المحظورة تبقى في «الكل» بشارتها');
        $this->assertSame([$blocked->id], $ids($holder, 'blocked'));
        $this->assertSame([], $ids($viewer, 'blocked'), 'تبويب المحظورين لمن يملك whatsapp.block وحده');

        $row = collect($this->actingAs($holder)->getJson('/api/whatsapp/conversations')->json('conversations'))->firstWhere('id', $blocked->id);
        $this->assertTrue($row['blocked']);

        $this->assertSame(1, InboxSummary::for($viewer)['waiting_staff']);
    }
}
