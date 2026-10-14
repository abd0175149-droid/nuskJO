<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * رد ميتا المتزامن بخطأ لا يرمي استثناءً: يعود برمز صريح من جدول WhatsAppChannel::META_CODES (أو META_ERROR)،
 * وتُحفظ الرسالة failed مع رمز ميتا ونص عربي.
 *
 * الرمز 131047 (انتهاء النافذة عند ميتا) لا يُختبر هنا: القفل المحلي يمنع الطلب قبل وصوله،
 * وميتا تبلّغ عنه في حمولة statuses (تغطيه اختبارات الويبهوك).
 */
class MetaErrorHandlingTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
    }

    /** أشكال الأخطاء كما في مرجع رموز أخطاء Cloud API */
    public static function metaErrors(): array
    {
        return [
            'rate limit 130429' => [400, 'RATE_LIMITED', '[130429] تجاوزنا حد الإرسال عند ميتا — أعد المحاولة بعد قليل.', [
                'message' => '(#130429) Rate limit hit',
                'type' => 'OAuthException',
                'code' => 130429,
                'error_data' => [
                    'messaging_product' => 'whatsapp',
                    'details' => 'Cloud API message throughput has been reached.',
                ],
                'fbtrace_id' => 'AbCdEfGhIjKlMnOpQrStUvW',
            ]],
            'expired token 190' => [401, 'TOKEN_INVALID', '[190] رمز ميتا منتهٍ أو مُلغى — يحدّثه المدير من إعدادات الواتساب.', [
                'message' => 'Error validating access token: Session has expired.',
                'type' => 'OAuthException',
                'code' => 190,
                'error_subcode' => 463,
                'fbtrace_id' => 'AbCdEfGhIjKlMnOpQrStUvX',
            ]],
            'window closed at meta 131047' => [400, 'WINDOW_EXPIRED', '[131047] انتهت نافذة الـ24 ساعة عند ميتا — لا يمكن الإرسال حتى يراسلنا العميل.', [
                'message' => 'Re-engagement message', 'type' => 'OAuthException', 'code' => 131047,
            ]],
            'unsupported 131051' => [400, 'UNSUPPORTED', '[131051] نوع الرسالة غير مدعوم في واتساب.', [
                'message' => 'Unsupported message type', 'type' => 'OAuthException', 'code' => 131051,
            ]],
            'invalid parameter 100' => [400, 'META_ERROR', '[100] معامل غير صالح في الطلب.', [
                'message' => '(#100) Invalid parameter',
                'type' => 'OAuthException',
                'code' => 100,
                'fbtrace_id' => 'AbCdEfGhIjKlMnOpQrStUvY',
            ]],
            'code missing from the catalog' => [400, 'META_ERROR', '[132000] Number of parameters does not match', [
                'message' => 'Number of parameters does not match',
                'type' => 'OAuthException',
                'code' => 132000,
            ]],
        ];
    }

    #[Group('INV-07')]
    #[DataProvider('metaErrors')]
    public function test_meta_error_response_returns_its_code_and_stores_failed_message(int $status, string $code, string $stored, array $error): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(['error' => $error], $status)]);
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendText($conv, 'أهلاً');

        $this->assertSame([
            'ok' => false,
            'error' => $stored,
            'code' => $code,
            'message_id' => null,
        ], $result);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v25.0/106540352242922/messages');
        Http::assertSentCount(1);

        $msg = WaMessage::sole();
        $this->assertSame('out', $msg->direction);
        $this->assertSame('failed', $msg->status);
        $this->assertSame($stored, $msg->error_message);
        $this->assertNull($msg->wamid);
        $this->assertSame('أهلاً', $msg->body);
        $this->assertSame('أهلاً', data_get($msg->payload, 'text.body'));
    }

    #[Group('INV-07')]
    #[DataProvider('metaErrors')]
    public function test_failed_send_does_not_mark_conversation_as_answered(int $status, string $code, string $stored, array $error): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(['error' => $error], $status)]);
        $conv = WaConversation::factory()->windowOpen()->create([
            'needs_attention' => true,
            'unread_count' => 2,
        ]);

        $result = WhatsAppChannel::sendText($conv, 'معك فريق المبيعات', 'staff', User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create()->id);

        $this->assertSame($code, $result['code']);
        Http::assertSentCount(1);

        $conv->refresh();
        $this->assertNull($conv->last_outbound_at);
        $this->assertNull($conv->bot_paused_until);
        $this->assertTrue($conv->needs_attention);
        $this->assertSame(2, $conv->unread_count);
        $this->assertSame('failed', WaMessage::sole()->status);
    }

    #[Group('INV-07')]
    public function test_error_response_without_json_body_falls_back_to_http_status(): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::response('<html>Bad Gateway</html>', 502)]);
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendText($conv, 'أهلاً');

        $this->assertFalse($result['ok']);
        $this->assertSame('META_ERROR', $result['code']);
        $this->assertSame('HTTP 502', $result['error']);
        Http::assertSentCount(1);

        $msg = WaMessage::sole();
        $this->assertSame('failed', $msg->status);
        $this->assertSame('HTTP 502', $msg->error_message);
    }

    #[Group('INV-07')]
    public function test_connection_failure_returns_http_error_without_throwing(): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::failedConnection('cURL error 28: Operation timed out')]);
        $conv = WaConversation::factory()->windowOpen()->create();

        $result = WhatsAppChannel::sendText($conv, 'أهلاً');

        $this->assertFalse($result['ok']);
        $this->assertSame('HTTP_ERROR', $result['code']);
        $this->assertNull($result['message_id']);
        $this->assertNull($conv->fresh()->last_outbound_at);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://graph.facebook.com/'));
    }
}
