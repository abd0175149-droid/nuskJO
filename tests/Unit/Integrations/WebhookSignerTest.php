<?php

namespace Tests\Unit\Integrations;

use App\Services\Integrations\WebhookSigner;
use PHPUnit\Framework\TestCase;

/** توقيع Standard Webhooks (E40.5): متجه المواصفة حرفياً، وتوقيعان خلال التدوير، وتحقق المستقبل بنافذة 5 دقائق */
class WebhookSignerTest extends TestCase
{
    // متجه الاختبار في مكتبات standard-webhooks الرسمية
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    private const MSG_ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';

    private const TIMESTAMP = 1614265330;

    private const BODY = '{"test": 2432232314}';

    public function test_the_specification_vector_signs_exactly(): void
    {
        $this->assertSame('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', WebhookSigner::sign(self::MSG_ID, self::TIMESTAMP, self::BODY, self::SECRET));
    }

    public function test_rotation_sends_two_signatures_separated_by_a_space_and_either_verifies(): void
    {
        $new = WebhookSigner::generateSecret();
        $header = WebhookSigner::header(self::MSG_ID, self::TIMESTAMP, self::BODY, [$new, self::SECRET]);

        $parts = explode(' ', $header);
        $this->assertCount(2, $parts);
        $this->assertStringStartsWith('v1,', $parts[0]);
        $this->assertTrue(WebhookSigner::verify(self::MSG_ID, self::TIMESTAMP, self::BODY, $header, self::SECRET, now: self::TIMESTAMP));
        $this->assertTrue(WebhookSigner::verify(self::MSG_ID, self::TIMESTAMP, self::BODY, $header, $new, now: self::TIMESTAMP));
        $this->assertCount(1, explode(' ', WebhookSigner::header(self::MSG_ID, self::TIMESTAMP, self::BODY, [$new])));
    }

    public function test_the_receiver_rejects_a_changed_body_or_an_old_timestamp(): void
    {
        $header = WebhookSigner::header(self::MSG_ID, self::TIMESTAMP, self::BODY, [self::SECRET]);

        $this->assertFalse(WebhookSigner::verify(self::MSG_ID, self::TIMESTAMP, '{"test": 1}', $header, self::SECRET, now: self::TIMESTAMP));
        $this->assertFalse(WebhookSigner::verify(self::MSG_ID, self::TIMESTAMP, self::BODY, $header, self::SECRET, now: self::TIMESTAMP + 301));
        $this->assertFalse(WebhookSigner::verify('msg_other', self::TIMESTAMP, self::BODY, $header, self::SECRET, now: self::TIMESTAMP));
    }

    public function test_generated_secrets_have_the_whsec_prefix_and_32_random_bytes(): void
    {
        $secret = WebhookSigner::generateSecret();

        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertSame(32, strlen(base64_decode(substr($secret, 6), true)));
        $this->assertNotSame($secret, WebhookSigner::generateSecret());
    }

    public function test_headers_carry_the_stable_id_and_a_separate_delivery_id(): void
    {
        $headers = WebhookSigner::headers('invoice_7_approved_1760000000', 1760000100, '{}', [self::SECRET], 'invoice.approved', 'd-1');

        $this->assertSame('invoice_7_approved_1760000000', $headers['webhook-id']);
        $this->assertSame('1760000100', $headers['webhook-timestamp']);
        $this->assertSame('invoice.approved', $headers['X-Nusk-Event']);
        $this->assertSame('d-1', $headers['X-Nusk-Delivery-Id']);
        $this->assertSame('NuskJO-Webhooks/1', $headers['User-Agent']);
        $this->assertArrayNotHasKey('X-Signature', $headers);
    }
}
