<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaCall;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\CallFollowup;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * المتابعة النصية بعد المكالمة الفائتة (E39، العلمان calls_webhook وcalls_followup): رسالة نظام واحدة بمفتاح المكالمة،
 * ومرة كل 6 ساعات للمحادثة، ولا متابعة لرقم إدارة ولا أثناء متابعة موظف ولا والإرسال موقوف. النص بحارس الردود الحتمية.
 */
class CallFollowupTest extends TestCase
{
    use CapturesLogs, ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        config(['features.env.calls_webhook' => true, 'features.env.calls_followup' => true]);
        Setting::set('company_phone', '+1 650 555 3333');
        User::factory()->admin()->create();
        $this->conv = WhatsAppInbox::conversationFor('16505551234');
        $this->assertTrue(CallFollowup::enabled());
    }

    private function missedCall(string $id = 'wacid.FIXTURE.call.2'): void
    {
        $payload = json_decode((string) file_get_contents($this->fixturePath('calls_terminate_failed.json')), true);
        $payload['entry'][0]['changes'][0]['value']['calls'][0]['id'] = $id;
        $payload['entry'][0]['changes'][0]['value']['calls'][0]['timestamp'] = (string) (now()->getTimestamp() - 10);
        $this->postRawWebhook((string) json_encode($payload))->assertOk();
    }

    private function followups()
    {
        return WaMessage::where('direction', 'out')->where('source', 'system')->get();
    }

    public function test_a_missed_call_sends_one_system_followup_keyed_by_the_call(): void
    {
        config(['features.env.send_ledger' => true]);

        $this->missedCall();
        $this->missedCall();   // إعادة ميتا للانتهاء نفسه

        $sent = $this->followups();
        $this->assertCount(1, $sent);
        $this->assertSame(SendKey::call('wacid.FIXTURE.call.2'), $sent[0]->send_key);
        $this->assertStringContainsString('وصلتنا مكالمتك', $sent[0]->body);
        $this->assertStringContainsString('+1 650 555 3333', $sent[0]->body);
        $this->assertStringNotContainsString('{company.phone}', $sent[0]->body);
        $this->assertSame($sent[0]->id, (int) WaCall::sole()->followup_message_id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/messages') && $r['to'] === '16505551234');
    }

    public function test_a_second_missed_call_within_six_hours_sends_nothing_more(): void
    {
        $this->missedCall('wacid.FIXTURE.call.2');
        $this->travel(2)->hours();
        $this->missedCall('wacid.FIXTURE.call.3');

        $this->assertCount(1, $this->followups());
        $this->assertSame(2, WaCall::where('missed', true)->count());
        $this->assertSame(1, (int) WaCall::whereNotNull('followup_message_id')->count());

        // بعد مضي المدة: متابعة جديدة
        $this->travel(5)->hours();
        $this->missedCall('wacid.FIXTURE.call.4');
        $this->assertCount(2, $this->followups());
    }

    public function test_no_followup_while_a_staff_member_is_handling_the_conversation(): void
    {
        $this->conv->forceFill(['bot_paused_until' => now()->addMinutes(20)])->save();

        $this->missedCall();

        $this->assertCount(0, $this->followups());
        Http::assertNothingSent();
        $this->assertTrue($this->conv->fresh()->needs_attention, 'الفائتة ترفع التدخّل رغم ذلك');
    }

    public function test_an_admin_number_gets_no_followup(): void
    {
        WaAdminNumber::create(['phone' => '16505551234', 'name' => 'المدير', 'is_active' => true]);

        $this->missedCall();

        $this->assertCount(0, $this->followups());
        Http::assertNothingSent();
    }

    public function test_a_suspended_channel_sends_nothing_but_the_staff_is_still_notified(): void
    {
        DB::table('wa_bot_settings')->update(['wa_suspended' => true]);

        $this->missedCall();

        $this->assertCount(0, $this->followups());
        Http::assertNothingSent();
        $this->assertSame(1, Notification::where('title', '📞 مكالمة فائتة')->count());
    }

    public function test_the_followup_flag_alone_controls_sending(): void
    {
        config(['features.env.calls_followup' => false]);

        $this->missedCall();

        $this->assertCount(0, $this->followups());
        $this->assertSame(1, WaCall::where('missed', true)->count());
    }

    public function test_a_text_with_a_written_phone_number_is_refused_and_logged(): void
    {
        $this->captureLogs();
        DB::table('wa_bot_settings')->update(['calls_followup_text' => 'اتصل بنا على 0790000000 أو اكتب هنا.']);

        $this->missedCall();

        $this->assertCount(0, $this->followups());
        $this->assertLogged('error', 'calls.followup_text_rejected');
    }

    public function test_the_english_text_is_chosen_when_the_customer_last_wrote_in_latin(): void
    {
        DB::table('wa_bot_settings')->update(['calls_followup_text_en' => 'We missed your call. Write to us here and we will follow up.']);
        WaMessage::create(['conversation_id' => $this->conv->id, 'direction' => 'in', 'source' => 'customer', 'msg_type' => 'text',
            'body' => 'Hello, do you have Umrah packages?', 'wamid' => 'wamid.TEST.latin']);

        $this->missedCall();

        $this->assertSame('We missed your call. Write to us here and we will follow up.', $this->followups()->sole()->body);
    }
}
