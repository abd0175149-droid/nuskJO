<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * الويبهوك خارج auth وخارج CSRF، وحمايته الوحيدة توقيع ميتا (X-Hub-Signature-256)
 * محسوباً على البايتات الخام للجسم بسر التطبيق.
 */
class WebhookSignatureTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configureChannel();
    }

    #[Group('INV-01')]
    public function test_valid_signature_is_accepted(): void
    {
        $this->postMetaWebhook('text.json')
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertSame(1, WaMessage::count());
    }

    public static function rejectedSignatures(): array
    {
        $raw = fn () => file_get_contents(base_path('tests/Fixtures/whatsapp/text.json'));

        return [
            'wrong secret' => [fn () => 'sha256='.hash_hmac('sha256', $raw(), 'another-secret')],
            'header missing' => [fn () => null],
            'empty header' => [fn () => ''],
            'bare hex without sha256= prefix' => [fn () => hash_hmac('sha256', $raw(), self::TEST_APP_SECRET)],
            'legacy sha1' => [fn () => 'sha1='.hash_hmac('sha1', $raw(), self::TEST_APP_SECRET)],
            'signature of another body' => [fn () => 'sha256='.hash_hmac('sha256', $raw().' ', self::TEST_APP_SECRET)],
        ];
    }

    #[Group('INV-01')]
    #[DataProvider('rejectedSignatures')]
    public function test_bad_signature_is_rejected_and_nothing_is_stored(\Closure $signature): void
    {
        $sig = $signature();

        $response = $sig === null
            ? $this->postMetaWebhook('text.json', secret: null)
            : $this->postMetaWebhook('text.json', signature: $sig);

        $response->assertForbidden();
        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, WaConversation::count());
        Queue::assertNothingPushed();
    }

    #[Group('INV-01')]
    public function test_missing_app_secret_rejects_everything(): void
    {
        $this->configureChannel(['wa_app_secret' => null]);

        // لا سر مهيّأ: لا يقبل توقيعاً بسر فارغ ولا بالسر القديم
        $this->postMetaWebhook('text.json', secret: '')->assertForbidden();
        $this->postMetaWebhook('text.json')->assertForbidden();

        $this->assertSame(0, WaMessage::count());
        Queue::assertNothingPushed();
    }

    #[Group('INV-01')]
    public function test_arabic_payload_is_verified_on_raw_bytes_and_stored_decoded(): void
    {
        // الملف ASCII خالص: العربي فيه مهرّب بصيغة \uXXXX كما ترسله ميتا
        $raw = file_get_contents($this->fixturePath('text_arabic_escaped.json'));
        $this->assertTrue(mb_check_encoding($raw, 'ASCII'));
        $this->assertStringContainsString(trim(json_encode('السلام'), '"'), $raw);

        $this->postMetaWebhook('text_arabic_escaped.json')->assertOk();

        $message = WaMessage::sole();
        $this->assertSame('السلام عليكم 🕋 هل عرض العمرة متاح؟', $message->body);
        $this->assertSame('عميل تجريبي', $message->conversation->display_name);
    }

    #[Group('INV-01')]
    public function test_signature_over_re_encoded_json_is_rejected(): void
    {
        $raw = file_get_contents($this->fixturePath('text_arabic_escaped.json'));

        // نفس المحتوى بترميز آخر (عربي غير مهرّب) = بايتات أخرى = توقيع آخر
        $reEncoded = json_encode(json_decode($raw, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertNotSame($raw, $reEncoded);

        $this->postRawWebhook($raw, signature: 'sha256='.hash_hmac('sha256', $reEncoded, self::TEST_APP_SECRET))
            ->assertForbidden();

        $this->assertSame(0, WaMessage::count());
    }

    #[Group('INV-01')]
    public function test_verify_handshake_echoes_challenge_as_plain_text(): void
    {
        $response = $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=test-verify&hub.challenge=123');

        $response->assertOk();
        $this->assertSame('123', $response->getContent());
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
    }

    public static function rejectedHandshakes(): array
    {
        return [
            'wrong token' => ['hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=123'],
            'token missing' => ['hub.mode=subscribe&hub.challenge=123'],
            'wrong mode' => ['hub.mode=unsubscribe&hub.verify_token=test-verify&hub.challenge=123'],
            'mode missing' => ['hub.verify_token=test-verify&hub.challenge=123'],
        ];
    }

    #[Group('INV-01')]
    #[DataProvider('rejectedHandshakes')]
    public function test_verify_handshake_rejects_bad_requests(string $query): void
    {
        $response = $this->get('/api/whatsapp/webhook?'.$query);

        $response->assertForbidden();
        $this->assertStringNotContainsString('123', $response->getContent());
    }

    #[Group('INV-01')]
    public function test_verify_handshake_fails_closed_when_no_token_is_configured(): void
    {
        $this->configureChannel(['wa_verify_token' => null]);

        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=&hub.challenge=123')->assertForbidden();
        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.challenge=123')->assertForbidden();
    }
}
