<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Setting;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\IdentityMode;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الصادر (E11.6): كل رسالة تحفظ الرقم الذي أُرسلت منه ورمز الخطأ الداخلي عند الفشل، وزر «طلب الرقم»
 * يُبنى request_contact_info ويمر بالأقفال نفسها (النافذة، ووضع الهوية، والإيقاف).
 */
class ChannelRecipientTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const BSUID = 'US.13491208655302741918';

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all', 'whatsapp.reply'], 'desk')->create();
    }

    private function phoneless(array $attrs = []): WaConversation
    {
        return WaConversation::factory()->windowOpen()->create($attrs + ['phone' => null, 'wa_phone' => null, 'wa_user_id' => self::BSUID]);
    }

    private function requestContact(WaConversation $conv, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->staff)->postJson("/api/whatsapp/conversations/{$conv->id}/request-contact");
    }

    public function test_outbound_messages_keep_our_number_and_the_internal_error_code(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.OUT.1']]])
            ->push(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400)]);
        $conv = WaConversation::factory()->windowOpen()->create();

        $this->assertTrue(WhatsAppChannel::sendText($conv, 'أهلاً')['ok']);
        $this->assertFalse(WhatsAppChannel::sendText($conv, 'ثانية')['ok']);

        $sent = WaMessage::where('status', 'sent')->sole();
        $failed = WaMessage::where('status', 'failed')->sole();
        $this->assertSame([self::TEST_PHONE_NUMBER_ID, null], [$sent->phone_number_id, $sent->error_code]);
        $this->assertSame([self::TEST_PHONE_NUMBER_ID, 'WINDOW_EXPIRED', 131047], [$failed->phone_number_id, $failed->error_code, $failed->meta_code]);
    }

    public function test_a_200_without_a_message_id_is_stored_as_unconfirmed(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp'])]);

        $result = WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'أهلاً');

        $this->assertSame([true, true, null], [$result['ok'], $result['unconfirmed'], $result['message_id']]);
        $this->assertSame(['unconfirmed', null], [WaMessage::sole()->status, WaMessage::sole()->wamid]);
    }

    public function test_a_staff_message_lost_to_a_timeout_stays_visible_as_unconfirmed(): void
    {
        Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        $conv = WaConversation::factory()->windowOpen()->create();

        $staff = WhatsAppChannel::sendText($conv, 'موعدك غداً', 'staff', $this->staff->id);
        $bot = WhatsAppChannel::sendText($conv, 'أهلاً');

        $this->assertSame([false, 'HTTP_ERROR', false, 'HTTP_ERROR'], [$staff['ok'], $staff['code'], $bot['ok'], $bot['code']]);
        $row = WaMessage::sole();
        // error_code رمز داخلي من MetaErrorCatalog (E18): المهلة HTTP_TIMEOUT
        $this->assertSame(['unconfirmed', 'staff', 'موعدك غداً', 'HTTP_TIMEOUT', null], [$row->status, $row->source, $row->body, $row->error_code, $row->wamid]);
    }

    public function test_the_contact_request_button_is_built_for_meta_and_labelled_in_the_inbox(): void
    {
        $this->fakeGraph();
        Setting::set('wa_identity_mode', IdentityMode::STAFF);
        $conv = $this->phoneless();

        $this->requestContact($conv)->assertOk()->assertJsonPath('ok', true);

        Http::assertSent(fn (Request $r) => $r['recipient'] === self::BSUID && !isset($r['to'])
            && $r['type'] === 'interactive' && $r['interactive']['type'] === 'request_contact_info'
            && $r['interactive']['action'] === ['name' => 'request_contact_info']
            && str_contains($r['interactive']['body']['text'], 'شارك رقم هاتفك'));
        $m = WaMessage::sole();
        $this->assertSame(['staff', 'interactive', WhatsAppChannel::CONTACT_REQUEST_LABEL, $this->staff->id], [$m->source, $m->msg_type, $m->body, $m->staff_id]);
    }

    public function test_it_is_refused_when_the_number_is_known_or_the_mode_or_window_forbid_it(): void
    {
        $this->fakeGraph();
        $this->requestContact(WaConversation::factory()->windowOpen()->create())->assertStatus(422)->assertJsonPath('code', 'HAS_PHONE');

        // store: لا إرسال بالمعرّف
        $this->requestContact($this->phoneless())->assertStatus(422)->assertJsonPath('code', WhatsAppChannel::ERR_NO_RECIPIENT);

        Setting::set('wa_identity_mode', IdentityMode::STAFF);
        $this->requestContact($this->phoneless(['wa_user_id' => 'US.2', 'last_inbound_at' => now()->subDays(2)]))
            ->assertStatus(422)->assertJsonPath('code', WhatsAppChannel::ERR_WINDOW);

        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
    }

    public function test_it_needs_the_reply_permission(): void
    {
        $this->fakeGraph();
        Setting::set('wa_identity_mode', IdentityMode::STAFF);
        $viewer = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all'], 'viewer')->create();

        $this->requestContact($this->phoneless(), $viewer)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_the_bot_may_send_it_only_in_full_mode(): void
    {
        $this->fakeGraph();
        Setting::set('wa_identity_mode', IdentityMode::STAFF);
        $conv = $this->phoneless();
        $this->assertSame(WhatsAppChannel::ERR_NO_RECIPIENT, WhatsAppChannel::sendContactRequest($conv, 'شارك رقمك', 'bot')['code']);
        $this->assertTrue(WhatsAppChannel::sendContactRequest($conv, 'شارك رقمك', 'system')['ok']);

        Setting::set('wa_identity_mode', IdentityMode::FULL);
        $this->assertTrue(WhatsAppChannel::sendContactRequest($conv, 'شارك رقمك', 'bot')['ok']);
        Http::assertSentCount(2);
    }
}
