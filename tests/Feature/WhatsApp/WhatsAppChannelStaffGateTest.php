<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaMessage;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * القفل 0 في بوّابة الإرسال: رسالة بصفة موظف لمحادثة خارج نطاقه تُرفض قبل أي طلب أو صف،
 * أياً كان مصدرها (الصندوق أو إرسال السعر). البوت والنظام لا يمرّان به.
 */
#[Group('INV-17')]
class WhatsAppChannelStaffGateTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->fakeGraph();
    }

    public function test_staff_outside_scope_is_refused_before_anything_is_sent_or_stored(): void
    {
        $specialist = $this->staffWith('umrah-desk', ['whatsapp.view', 'whatsapp.reply'], ['umrah']);
        $conversation = $this->conversationTagged(['visa']);

        $result = WhatsAppChannel::sendText($conversation, 'مرحباً', 'staff', $specialist->id);

        $this->assertFalse($result['ok']);
        $this->assertSame(WhatsAppChannel::ERR_OUT_OF_SCOPE, $result['code']);
        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
    }

    public function test_staff_without_an_id_is_refused(): void
    {
        $result = WhatsAppChannel::sendText($this->conversationTagged(), 'مرحباً', 'staff');

        $this->assertSame(WhatsAppChannel::ERR_OUT_OF_SCOPE, $result['code']);
        Http::assertNothingSent();
    }

    public function test_staff_in_scope_sends(): void
    {
        $specialist = $this->staffWith('umrah-desk', ['whatsapp.view', 'whatsapp.reply'], ['umrah']);

        $result = WhatsAppChannel::sendText($this->conversationTagged(['umrah']), 'مرحباً', 'staff', $specialist->id);

        $this->assertTrue($result['ok']);
        Http::assertSentCount(1);
    }

    public function test_bot_and_system_messages_do_not_pass_through_the_staff_gate(): void
    {
        $conversation = $this->conversationTagged();

        $this->assertTrue(WhatsAppChannel::sendText($conversation, 'ردّ آلي', 'bot')['ok']);
        $this->assertTrue(WhatsAppChannel::sendText($conversation, 'تنبيه', 'system')['ok']);
        Http::assertSentCount(2);
    }
}
