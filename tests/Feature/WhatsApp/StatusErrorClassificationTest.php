<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * الأخطاء غير المتزامنة (E18): حالة failed من الويبهوك تكتب الأعمدة الأربعة بنص عربي، ورسالة البوت تحتاج موظفاً،
 * ورسالة الموظف تُبلغ مرسلها مرة واحدة ولو أعادت ميتا الويبهوك، وأخطاء الحمولة تُسجَّل ولا تُكتب في الرسائل.
 */
class StatusErrorClassificationTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const WAMID = 'wamid.HBgLMTY1MDU1NTEyMzQVAgARGBIxMzEwMjZVTkRFTElWRVJBQkxFAA==';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        config(['features.env.meta_error_v2' => true]);
        User::factory()->admin()->create();
    }

    public function test_failed_bot_message_gets_the_four_columns_and_needs_a_human(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $out = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'wamid' => self::WAMID]);

        $this->postMetaWebhook('status_failed_131026.json')->assertOk();

        $out->refresh();
        $this->assertSame(['failed', 131026, 'RECIPIENT_UNREACHABLE', 'caller'], [$out->status, $out->meta_code, $out->error_code, $out->error_category]);
        $this->assertSame('تعذّر التسليم: غالباً تطبيق العميل قديم أو لم يقبل شروط واتساب الأخيرة.', $out->error_message);
        $this->assertSame('Message Undeliverable.', data_get($out->payload, '_meta_error.details'));
        $conv->refresh();
        $this->assertTrue($conv->needs_attention);
        $this->assertSame('send_failed', $conv->attention_reason);
        $this->assertNull(WaBotSetting::current()->wa_last_error, 'خطأ عميل واحد');
    }

    public function test_failed_staff_message_notifies_its_sender_once(): void
    {
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply'])->create();
        $conv = WaConversation::factory()->windowOpen()->create(['display_name' => 'سارة']);
        WaMessage::factory()->fromStaff($staff)->create(['conversation_id' => $conv->id, 'wamid' => self::WAMID, 'body' => 'نص سري للموظف']);

        $this->postMetaWebhook('status_failed_131026.json')->assertOk();
        $this->postMetaWebhook('status_failed_131026.json')->assertOk();

        $notes = Notification::where('user_id', $staff->id)->get();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('سارة', $notes[0]->body);
        $this->assertStringContainsString('تعذّر التسليم', $notes[0]->body);
        $this->assertStringNotContainsString('نص سري', $notes[0]->body);
    }

    public function test_the_delivery_failure_log_line_has_ids_only(): void
    {
        $this->captureLogs();
        $conv = WaConversation::factory()->windowOpen()->create();
        $out = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'wamid' => self::WAMID]);

        $this->postMetaWebhook('status_failed_131026.json')->assertOk();

        $this->assertLogged('warning', 'wa.delivery_failed', ['conv_id' => $conv->id, 'message_id' => $out->id, 'wamid' => self::WAMID,
            'meta_code' => 131026, 'error_code' => 'RECIPIENT_UNREACHABLE', 'category' => 'caller']);
    }

    public function test_payload_level_errors_are_logged_and_not_stored(): void
    {
        $this->captureLogs();

        $this->postMetaWebhook('value_errors_130429.json')->assertOk();

        $this->assertLogged('warning', 'wa.webhook_error', ['field' => 'messages', 'meta_code' => 130429, 'category' => 'throttle', 'error_code' => 'RATE_LIMITED']);
        $this->assertSame(0, WaMessage::count());
    }

    public function test_status_restricted_suspends_through_the_same_reactor(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'wamid' => 'wamid.STATUS.368']);
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '102290129340398', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => self::TEST_PHONE_NUMBER_ID],
            'statuses' => [['id' => 'wamid.STATUS.368', 'status' => 'failed', 'timestamp' => '1760425200', 'recipient_id' => '16505551234',
                'errors' => [['code' => 368, 'title' => 'Temporarily blocked for policies violations']]]],
        ]]]]]];

        $this->postRawWebhook(json_encode($payload))->assertOk();

        $s = WaBotSetting::current();
        $this->assertTrue($s->wa_suspended);
        $this->assertSame('meta_event', $s->wa_suspended_source);
        $this->assertSame(['ACCOUNT_RESTRICTED', 'integrity'], [$s->wa_last_error['error_code'], $s->wa_last_error['category']]);
    }
}
