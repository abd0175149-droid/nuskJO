<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\Client;
use App\Models\Notification;
use App\Models\QuoteRequest;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaOptout;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\IdentityMode;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * E11a: عميل باسم مستخدم واتساب يصل بلا رقم (from_user_id وحده). كانت رسالته تُسقط بصمت.
 * تُحفظ الآن في محادثة تُعرف بالمعرّف، ويُرد عليه بـ recipient حسب وضع الهوية، ولا بيانات مالية بلا رقم.
 */
#[Group('INV-11')]
class IdentityWebhookTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    private const BSUID = 'US.13491208655302741918';

    private const PHONE = '16505551234';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        $this->admin = User::factory()->admin()->create();
    }

    /** التثبيتة بوقت الآن (النافذة من وقت ميتا) */
    private function receive(string $fixture, array $override = [])
    {
        $payload = json_decode(file_get_contents($this->fixturePath($fixture)), true);
        data_set($payload, 'entry.0.changes.0.value.messages.0.timestamp', (string) now()->timestamp);
        foreach ($override as $key => $value) {
            data_set($payload, $key, $value);
        }

        return $this->postRawWebhook(json_encode($payload));
    }

    private function mode(string $mode): void
    {
        Setting::set('wa_identity_mode', $mode);
    }

    // ==================== الاستقبال ====================

    public function test_a_username_customer_without_a_number_is_kept_not_dropped(): void
    {
        $this->receive('username_only.json')->assertOk();

        $message = WaMessage::sole();
        $this->assertSame('Is the Umrah offer still available?', $message->body);

        $conv = $message->conversation;
        $this->assertNull($conv->phone);
        $this->assertNull($conv->wa_phone);
        $this->assertSame(self::BSUID, $conv->wa_user_id);
        $this->assertSame('sheena.travels', $conv->wa_username);
        $this->assertSame('Sheena Nelson', $conv->wa_profile_name);
        $this->assertNull($conv->client_id);
        $this->assertTrue($conv->isWindowOpen());
        // الوضع store: لا بوت، والمحادثة لموظف بسبب ظاهر
        $this->assertTrue($conv->needs_attention);
        $this->assertSame('no_phone', $conv->attention_reason);
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_in_full_mode_the_bot_gets_the_phoneless_conversation(): void
    {
        $this->mode(IdentityMode::FULL);

        $this->receive('username_only.json')->assertOk();

        Queue::assertPushed(ProcessWhatsAppReply::class, 1);
        $this->assertFalse(WaConversation::sole()->needs_attention);
    }

    public function test_number_and_id_together_make_one_conversation_linked_by_number(): void
    {
        $client = Client::factory()->create(['phone' => '+'.self::PHONE]);

        $this->receive('username_with_phone.json')->assertOk();

        $conv = WaConversation::sole();
        $this->assertSame(self::PHONE, $conv->phone);
        $this->assertSame(self::BSUID, $conv->wa_user_id);
        $this->assertSame($client->id, $conv->client_id);
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);
    }

    public function test_the_number_arriving_later_fills_the_same_conversation(): void
    {
        $client = Client::factory()->create(['phone' => '+'.self::PHONE]);
        $this->receive('username_only.json');
        $first = WaConversation::sole();

        $this->receive('username_with_phone.json')->assertOk();

        $conv = WaConversation::sole();
        $this->assertSame($first->id, $conv->id);
        $this->assertSame(self::PHONE, $conv->phone);
        $this->assertSame(self::PHONE, $conv->wa_phone);
        $this->assertSame($client->id, $conv->client_id);
        $this->assertSame(2, WaMessage::count());
    }

    public function test_an_id_that_looks_like_a_number_never_links_a_client(): void
    {
        Client::factory()->create(['phone' => '+13491208655302741918']);

        $this->receive('username_only.json')->assertOk();

        $this->assertNull(WaConversation::sole()->client_id);
    }

    public function test_number_and_id_in_two_conversations_are_merged_into_the_phone_conversation(): void
    {
        $byId = WaConversation::factory()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => self::BSUID, 'unread_count' => 2]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $byId->id, 'body' => 'رسالة قبل معرفة الرقم']);
        $byPhone = WaConversation::factory()->create(['phone' => self::PHONE, 'wa_phone' => self::PHONE, 'unread_count' => 1]);

        $this->receive('username_with_phone.json')->assertOk();

        $this->assertSame(2, WaMessage::where('conversation_id', $byPhone->id)->count(), 'رسائل محادثة المعرّف انتقلت');
        $this->assertSame(['merged', $byPhone->id, null], [$byId->fresh()->status, $byId->fresh()->merged_into_id, $byId->fresh()->wa_user_id]);
        $this->assertSame(self::BSUID, $byPhone->fresh()->wa_user_id);
        $this->assertSame(4, $byPhone->fresh()->unread_count);
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'wa.merge')->count());
    }

    public function test_number_and_id_of_two_different_clients_are_flagged_not_merged(): void
    {
        $byId = WaConversation::factory()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => self::BSUID, 'client_id' => Client::factory()->create()->id]);
        $byPhone = WaConversation::factory()->create(['phone' => self::PHONE, 'wa_phone' => self::PHONE, 'client_id' => Client::factory()->create()->id]);

        $this->receive('username_with_phone.json')->assertOk();

        $this->assertSame($byPhone->id, WaMessage::sole()->conversation_id);
        foreach ([$byId, $byPhone] as $conv) {
            $this->assertTrue($conv->fresh()->needs_attention);
            $this->assertSame('identity_conflict', $conv->fresh()->attention_reason);
            $this->assertNotSame('merged', $conv->fresh()->status);
        }
        $this->assertSame(self::BSUID, $byId->fresh()->wa_user_id);
    }

    public function test_a_phoneless_opt_out_is_kept_by_id(): void
    {
        $this->receive('username_only.json', ['entry.0.changes.0.value.messages.0.text.body' => 'إيقاف'])->assertOk();

        $optout = WaOptout::sole();
        $this->assertNull($optout->phone);
        $this->assertSame(self::BSUID, $optout->wa_user_id);
    }

    public function test_a_message_with_neither_number_nor_id_is_still_refused(): void
    {
        $this->receive('username_only.json', ['entry.0.changes.0.value.messages.0.from_user_id' => null])->assertOk();

        $this->assertSame(0, WaMessage::count());
    }

    // ==================== الإرسال ====================

    private function phoneless(): WaConversation
    {
        return WaConversation::factory()->windowOpen()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => self::BSUID]);
    }

    private function staff(): User
    {
        return User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
    }

    public function test_in_store_mode_nobody_can_send_to_a_phoneless_customer(): void
    {
        $result = WhatsAppChannel::sendText($this->phoneless(), 'أهلاً', 'staff', $this->staff()->id);

        $this->assertSame(WhatsAppChannel::ERR_NO_RECIPIENT, $result['code']);
        Http::assertNothingSent();
    }

    public function test_in_staff_mode_staff_send_by_recipient_and_the_bot_does_not(): void
    {
        $this->mode(IdentityMode::STAFF);
        $conv = $this->phoneless();

        $this->assertTrue(WhatsAppChannel::sendText($conv, 'أهلاً بك', 'staff', $this->staff()->id)['ok']);
        $this->assertSame(WhatsAppChannel::ERR_NO_RECIPIENT, WhatsAppChannel::sendText($conv, 'رد آلي', 'bot')['code']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['recipient'] === self::BSUID && !isset($r['to']) && $r['text']['body'] === 'أهلاً بك');
    }

    public function test_a_known_number_is_always_sent_with_to_only(): void
    {
        $this->mode(IdentityMode::FULL);
        $conv = WaConversation::factory()->windowOpen()->create(['phone' => self::PHONE, 'wa_phone' => self::PHONE, 'wa_user_id' => self::BSUID]);

        WhatsAppChannel::sendText($conv, 'أهلاً');

        Http::assertSent(fn (Request $r) => $r['to'] === self::PHONE && !isset($r['recipient']));
    }

    // ==================== البوت ====================

    public function test_the_bot_stays_out_of_a_phoneless_conversation_unless_full(): void
    {
        $conv = $this->phoneless();
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'كم رصيدي؟']);

        $result = BotEngine::reply($conv);

        $this->assertSame('no_phone', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_no_financial_data_without_a_number_even_if_a_client_is_attached(): void
    {
        $this->mode(IdentityMode::FULL);
        $conv = $this->phoneless();
        $conv->forceFill(['client_id' => Client::factory()->create()->id])->save();

        $this->assertNull($conv->fresh()->financialClientId());
        foreach (['get_my_balance', 'get_my_invoices', 'get_my_trips'] as $tool) {
            $out = BotTools::execute($tool, [], $conv->fresh());
            $this->assertArrayNotHasKey('balance_jod', $out, $tool);
            $this->assertArrayNotHasKey('invoices', $out, $tool);
        }
    }

    public function test_a_quote_from_a_phoneless_customer_is_stored_with_an_empty_phone(): void
    {
        $this->mode(IdentityMode::FULL);
        $conv = $this->phoneless();

        $out = BotTools::execute('request_quote', ['type' => 'flight', 'route_from' => 'عمّان', 'route_to' => 'جدة',
            'depart_date' => now()->addDays(20)->toDateString(), 'adults' => 1, 'details' => 'تذكرة واحدة'], $conv);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('', QuoteRequest::sole()->phone);
    }

    // ==================== الهجرة والوضع ====================

    public function test_the_migration_is_idempotent_and_leaves_phone_nullable(): void
    {
        $migration = require database_path('migrations/2026_10_14_000009_wa_identity_bsuid.php');
        $migration->up();

        $phone = collect(Schema::getColumns('wa_conversations'))->firstWhere('name', 'phone');
        $this->assertTrue($phone['nullable']);
        $this->assertTrue(Schema::hasIndex('wa_conversations', 'wa_conversations_phone_unique'));
        $this->assertTrue(Schema::hasIndex('wa_conversations', 'wa_conversations_wa_user_id_unique'));
    }

    public function test_the_identity_mode_command_accepts_only_known_modes(): void
    {
        $this->artisan('whatsapp:identity-mode', ['mode' => 'everything'])->assertFailed();
        $this->assertSame(IdentityMode::STORE, IdentityMode::current());

        $this->artisan('whatsapp:identity-mode', ['mode' => 'staff'])->assertSuccessful();
        $this->assertSame(IdentityMode::STAFF, IdentityMode::current());
    }
}
