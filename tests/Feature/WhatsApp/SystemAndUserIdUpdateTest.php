<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
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
 * تغيّر المعرّف (E11.5): رسالة system من نوع user_changed_user_id، والحقل user_id_update، يحدّثان المحادثة نفسها
 * ولا يمسّان الإيقاف ولا يوقظان البوت؛ وما كان لرقم آخر يُتجاهل. وأخطاء الحمولة تُسجَّل ولا توقف المعالجة.
 */
class SystemAndUserIdUpdateTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const PHONE = '16505551234';

    private const OLD = 'US.13491208655302741918';

    private const NEW = 'US.99887766554433221100';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        User::factory()->admin()->create();
    }

    private function change(string $field, array $value, string $pnid = self::TEST_PHONE_NUMBER_ID)
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [[
            'field' => $field, 'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => $pnid]] + $value,
        ]]]]];

        return $this->postRawWebhook(json_encode($payload));
    }

    public function test_a_system_id_change_moves_the_conversation_to_the_new_id(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create(['phone' => self::PHONE, 'wa_phone' => self::PHONE, 'wa_user_id' => self::OLD]);

        $this->change('messages', ['messages' => [['from' => self::PHONE, 'from_user_id' => self::OLD, 'id' => 'wamid.SYS1', 'timestamp' => (string) now()->timestamp,
            'type' => 'system', 'system' => ['body' => 'changed', 'type' => 'user_changed_user_id', 'user_id' => self::NEW]]]])->assertOk();

        $this->assertSame(self::NEW, $conv->fresh()->wa_user_id);
        $this->assertSame(['system', '🔄 تغيّر معرّف واتساب للعميل'], [WaMessage::sole()->source, WaMessage::sole()->body]);
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
    }

    public function test_the_user_id_update_field_moves_previous_to_current_and_never_suspends(): void
    {
        $conv = WaConversation::factory()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => self::OLD]);

        $this->change('user_id_update', ['user_id_update' => [['user_id' => ['previous' => self::OLD, 'current' => self::NEW]]]])->assertOk();

        $this->assertSame(self::NEW, $conv->fresh()->wa_user_id);
        $this->assertFalse((bool) WaBotSetting::current()->fresh()->wa_suspended);
    }

    public function test_an_update_for_another_number_or_with_a_bad_id_is_ignored(): void
    {
        $conv = WaConversation::factory()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => self::OLD]);

        $this->change('user_id_update', ['user_id_update' => [['user_id' => ['previous' => self::OLD, 'current' => self::NEW]]]], '999000999')->assertOk();
        $this->change('user_id_update', ['user_id_update' => [['user_id' => ['previous' => self::OLD, 'current' => '16505553333']]]])->assertOk();
        $this->change('user_id_update', ['user_id_update' => [['user_id' => ['previous' => self::OLD, 'current' => 'US.ENT.123']]]])->assertOk();

        $this->assertSame(self::OLD, $conv->fresh()->wa_user_id);
    }

    public function test_payload_errors_are_logged_and_messages_still_stored(): void
    {
        $this->captureLogs();

        $this->change('messages', [
            'errors' => [['code' => 131051, 'title' => 'Unsupported message type']],
            'contacts' => [['profile' => ['name' => 'عميل'], 'wa_id' => self::PHONE]],
            'messages' => [['from' => self::PHONE, 'id' => 'wamid.E1', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'مرحبا']]],
        ])->assertOk();

        $this->assertLogged('warning', 'wa.webhook_error');
        $this->assertSame('مرحبا', WaMessage::sole()->body);
    }
}
