<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * رد البوابة غير الناجح مصنَّفاً (منذ OpenWA، 2026-11-06): صف failed بالرمز والفئة ونص عربي، ونتيجة بالفئة والإجراء،
 * وفشل الشبكة يترك صفاً بلا رابط، والمهلة «غير مؤكدة»، والأقفال المحلية بلا صف، وسطر السجل بالمعرّفات وحدها.
 */
class ChannelErrorClassificationTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.meta_error_v2' => true, 'audit.extended' => true]);
    }

    private function failWith(int $status, array $body): void
    {
        $this->fakeGateway(['*/messages/send-text' => Http::response($body, $status)]);
    }

    private function send(string $source = 'bot', ?int $staffId = null): array
    {
        return WhatsAppChannel::sendText(WaConversation::factory()->create(), 'أهلاً بك', $source, $staffId);
    }

    public function test_a_gateway_rejection_stores_the_code_and_an_arabic_reason(): void
    {
        $this->failWith(400, ['statusCode' => 400, 'message' => ['text must be a string'], 'error' => 'Bad Request']);

        $result = $this->send();

        $row = WaMessage::sole();
        $this->assertSame(['failed', 'GW_REJECTED', 'caller'], [$row->status, $row->error_code, $row->error_category]);
        $this->assertSame('رفضت البوابة الرسالة (الصيغة أو الحجم).', $row->error_message);
        $this->assertStringNotContainsString('Bad Request', $row->error_message);

        $this->assertFalse($result['ok']);
        $this->assertSame(['GW_REJECTED', 'caller', false, 'message'], [$result['code'], $result['category'], $result['retryable'], $result['scope']]);
        $this->assertNotSame('', $result['action']);
    }

    public function test_a_number_not_on_whatsapp_is_a_recipient_error_with_masked_details(): void
    {
        $this->failWith(400, ['statusCode' => 400, 'message' => 'Number 962790000001 is not registered on WhatsApp']);

        $result = $this->send();

        $row = WaMessage::sole();
        $this->assertSame(['RECIPIENT_NOT_ON_WA', 'quality', 'recipient'], [$result['code'], $result['category'], $result['scope']]);
        $this->assertSame('RECIPIENT_NOT_ON_WA', $row->error_code);
        $this->assertStringNotContainsString('962790000001', (string) $row->error_message);
    }

    public function test_connection_failure_leaves_a_visible_row_without_leaking_the_url(): void
    {
        $this->fakeGateway(['*/messages/send-text' => Http::failedConnection('cURL error 7: Failed to connect to nuskjo-openwa port 2785 after 3 ms: X-API-Key=key-1')]);

        $result = $this->send();

        $row = WaMessage::sole();
        $this->assertSame(['failed', 'GW_UNREACHABLE', 'transient', 'bot'], [$row->status, $row->error_code, $row->error_category, $row->source]);
        $this->assertSame('GW_UNREACHABLE', $result['code']);
        foreach ([$result['error'], $row->error_message] as $text) {
            $this->assertStringNotContainsString('nuskjo-openwa:2785', $text);
            $this->assertStringNotContainsString('key-1', $text);
            $this->assertStringNotContainsString('cURL', $text);
        }
    }

    public function test_a_timeout_is_unconfirmed_and_the_send_endpoint_does_not_claim_failure(): void
    {
        $this->fakeGateway(['*/messages/send-text' => Http::failedConnection('cURL error 28: Operation timed out after 90001 milliseconds with 0 bytes received')]);
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $conv = WaConversation::factory()->create();

        $this->actingAs($staff)->postJson("/api/whatsapp/conversations/{$conv->id}/send", ['body' => 'موعدك غداً'])->assertStatus(202);

        $this->assertSame(['unknown', 'GW_TIMEOUT'], [WaMessage::sole()->status, WaMessage::sole()->error_code]);
    }

    public function test_send_endpoint_returns_category_and_action_on_failure(): void
    {
        $this->failWith(400, ['statusCode' => 400, 'message' => 'invalid payload']);
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $conv = WaConversation::factory()->create();

        $this->actingAs($staff)->postJson("/api/whatsapp/conversations/{$conv->id}/send", ['body' => 'مرحباً'])
            ->assertStatus(422)->assertJsonPath('category', 'caller')->assertJsonPath('code', 'GW_REJECTED')
            ->assertJsonPath('action', 'راجع الرسالة أو الملف وأرسل من جديد.');
    }

    public function test_local_locks_carry_their_category_without_a_row(): void
    {
        $this->fakeGateway();
        $this->testChannel()->update(['paused_until' => now()->addHour(), 'pause_reason' => 'manual']);

        $result = $this->send();

        $this->assertSame(['CHANNEL_PAUSED', 'integrity', false], [$result['code'], $result['category'], $result['retryable']]);
        $this->assertSame(0, WaMessage::count());
        Http::assertNothingSent();
    }

    public function test_the_failure_log_line_carries_ids_only(): void
    {
        $this->captureLogs();
        $this->failWith(400, ['statusCode' => 400, 'message' => 'Session has expired for 962790000001']);

        $this->send();

        $line = collect($this->logged)->firstWhere('message', 'wa.send_failed');
        $this->assertNotNull($line);
        $this->assertSame([], array_keys(array_diff_key($line['context'], WaLog::filter($line['context']))), 'كل المفاتيح من القائمة المسموحة');
        $this->assertSame(['GW_REJECTED', 'caller', 400, $this->testChannel()->id], [$line['context']['error_code'], $line['context']['category'], $line['context']['http'], $line['context']['channel_id']]);
        $serialized = json_encode($this->logged, JSON_UNESCAPED_UNICODE);
        foreach (['key-1', 'admin-test-key', '962790000001', 'أهلاً بك', 'Session has expired'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized, $secret);
        }
    }

    public function test_bot_note_never_carries_gateway_text(): void
    {
        $code = (string) file_get_contents(app_path('Services/WhatsApp/BotTools.php'));

        $this->assertDoesNotMatchRegularExpression("/'note'\s*=>[^\n]*\\\$res\['error'\]/", $code);
    }
}
