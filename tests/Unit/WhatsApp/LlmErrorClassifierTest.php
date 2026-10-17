<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\Llm\Exceptions\LlmAuthFailed;
use App\Services\WhatsApp\Llm\Exceptions\LlmBadRequest;
use App\Services\WhatsApp\Llm\Exceptions\LlmQuotaExhausted;
use App\Services\WhatsApp\Llm\Exceptions\LlmRateLimited;
use App\Services\WhatsApp\Llm\Exceptions\LlmUnavailable;
use App\Services\WhatsApp\Llm\LlmErrorClassifier;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** تصنيف أخطاء المزوّد (E12.2): ما يُعاد، وما يعدّه القاطع، وما يفتحه فوراً؛ والرسالة بلا مفتاح ولا رابط */
class LlmErrorClassifierTest extends TestCase
{
    private static function res(int $status, array $json, array $headers = []): Response
    {
        return new Response(new Psr7Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($json)));
    }

    public static function gemini(): array
    {
        $err = fn (array $extra = []) => ['error' => ['message' => 'x'] + $extra];

        return [
            'key invalid' => [400, $err(['details' => [['reason' => 'API_KEY_INVALID']]]), LlmAuthFailed::class],
            'billing' => [400, $err(['status' => 'FAILED_PRECONDITION']), LlmQuotaExhausted::class],
            'bad request' => [400, $err(), LlmBadRequest::class],
            'forbidden' => [403, $err(), LlmAuthFailed::class],
            'payment' => [402, $err(), LlmQuotaExhausted::class],
            'model gone' => [404, $err(), LlmAuthFailed::class],
            'per day' => [429, $err(['details' => [['violations' => [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier']]]]]), LlmQuotaExhausted::class],
            'per minute' => [429, $err(['details' => [['violations' => [['quotaId' => 'GenerateRequestsPerMinutePerProject']]], ['retryDelay' => '37s']]]), LlmRateLimited::class],
            '500' => [500, $err(), LlmUnavailable::class],
            '503' => [503, $err(), LlmUnavailable::class],
            '504' => [504, $err(), LlmUnavailable::class],
        ];
    }

    #[DataProvider('gemini')]
    public function test_gemini_responses(int $status, array $json, string $class): void
    {
        $e = LlmErrorClassifier::fromResponse('google', self::res($status, $json));

        $this->assertInstanceOf($class, $e);
        $this->assertSame([$status, 'google'], [$e->httpStatus, $e->provider]);
    }

    public function test_gemini_retry_delay_and_flags(): void
    {
        $limited = LlmErrorClassifier::fromResponse('google', self::res(429, ['error' => ['details' => [['retryDelay' => '37s']]]]));
        $this->assertSame([37, true, false, false], [$limited->retryAfter, $limited->counts(), $limited->tripsImmediately(), $limited->retryable()]);

        $short = LlmErrorClassifier::fromResponse('google', self::res(429, ['error' => ['details' => [['retryDelay' => '1.2s']]]]));
        $this->assertTrue($short->retryable(), 'خنق قصير يُعاد داخلياً');

        $bad = LlmErrorClassifier::fromResponse('google', self::res(400, ['error' => ['message' => 'x']]));
        $this->assertFalse($bad->counts());
        $this->assertTrue(LlmErrorClassifier::fromResponse('google', self::res(403, []))->tripsImmediately());
    }

    public static function anthropic(): array
    {
        return [
            'overloaded' => [529, [], [], LlmUnavailable::class, null],
            'rate limited' => [429, ['error' => ['type' => 'rate_limit_error']], ['retry-after' => '20'], LlmRateLimited::class, 20],
            'spend cap' => [429, ['error' => ['type' => 'rate_limit_error']], [], LlmQuotaExhausted::class, null],
            'billing' => [402, ['error' => ['type' => 'billing_error']], [], LlmQuotaExhausted::class, null],
            'spend limit 400' => [400, ['error' => ['message' => 'You have reached your spend limit']], [], LlmQuotaExhausted::class, null],
            'too large' => [413, [], [], LlmBadRequest::class, null],
            'unauthorized' => [401, [], [], LlmAuthFailed::class, null],
            'server' => [500, [], [], LlmUnavailable::class, null],
        ];
    }

    #[DataProvider('anthropic')]
    public function test_anthropic_responses(int $status, array $json, array $headers, string $class, ?int $retry): void
    {
        $e = LlmErrorClassifier::fromResponse('anthropic', self::res($status, $json, $headers));

        $this->assertInstanceOf($class, $e);
        $this->assertSame($retry, $e->retryAfter);
    }

    public function test_connection_errors_are_unavailable_and_other_errors_are_not_provider_errors(): void
    {
        $e = LlmErrorClassifier::fromThrowable('google', new ConnectionException('cURL error 28 for https://generativelanguage.googleapis.com/v1beta/models?key=AIzaSyA1234567890123456789012345678901'));

        $this->assertInstanceOf(LlmUnavailable::class, $e);
        $this->assertNull($e->httpStatus);
        $this->assertNull(LlmErrorClassifier::fromThrowable('google', new \TypeError('bug')));
    }

    public function test_messages_never_carry_keys_or_urls(): void
    {
        $leaky = 'bad key AIzaSyA1234567890123456789012345678901 and sk-ant-api03-abcdefghijklmnopqrstu at https://x.test/v1?key=secret123';
        foreach ([
            LlmErrorClassifier::fromResponse('google', self::res(400, ['error' => ['message' => $leaky]])),
            LlmErrorClassifier::fromResponse('anthropic', self::res(500, ['error' => ['message' => $leaky]])),
        ] as $e) {
            $this->assertStringNotContainsString('AIza', $e->getMessage());
            $this->assertStringNotContainsString('sk-ant-', $e->getMessage());
            $this->assertStringNotContainsString('key=secret', $e->getMessage());
            $this->assertStringNotContainsString('https://', $e->getMessage());
            $this->assertLessThanOrEqual(200, mb_strlen($e->getMessage()));
        }
    }
}
