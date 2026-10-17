<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\MetaError;
use App\Services\WhatsApp\MetaErrorCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * الجدول الوحيد لرموز ميتا (E18): الفئة والرمز الداخلي وسياسة الإعادة لكل رمز موثّق، وقائمة «لا إعادة»،
 * وتقنيع details، وحارس الإصدار الذي يفرض مراجعة الجدول مع كل ترقية Graph.
 */
class MetaErrorCatalogTest extends TestCase
{
    public static function documented(): array
    {
        return [
            '190 token' => [190, null, 'auth', 'TOKEN_INVALID', 'none', true, 'channel'],
            '0 auth exception' => [0, null, 'auth', 'TOKEN_INVALID', 'none', true, 'channel'],
            '131047 window' => [131047, null, 'window', 'WINDOW_EXPIRED', 'none', false, 'recipient'],
            '131056 pair rate' => [131056, null, 'throttle', 'PAIR_RATE_LIMITED', 'backoff_4x', false, 'recipient'],
            '130429 throughput' => [130429, null, 'throttle', 'RATE_LIMITED', 'backoff_4x', false, 'channel'],
            '4 app rate' => [4, null, 'throttle', 'RATE_LIMITED', 'backoff_4x', false, 'channel'],
            '80007 waba rate' => [80007, null, 'throttle', 'RATE_LIMITED', 'backoff_4x', false, 'channel'],
            '131000 unknown at meta' => [131000, null, 'transient', 'META_TRANSIENT', 'once', false, 'message'],
            '131016 service unavailable' => [131016, null, 'transient', 'META_TRANSIENT', 'once', false, 'message'],
            '2 temporary' => [2, null, 'transient', 'META_TRANSIENT', 'once', false, 'message'],
            '133010 business number not registered' => [133010, null, 'auth', 'CHANNEL_NOT_REGISTERED', 'none', true, 'channel'],
            '130497 country' => [130497, null, 'caller', 'COUNTRY_RESTRICTED', 'none', false, 'recipient'],
            '131042 billing' => [131042, null, 'auth', 'BILLING_ISSUE', 'none', true, 'channel'],
            '131031 locked' => [131031, null, 'integrity', 'ACCOUNT_LOCKED', 'none', true, 'channel'],
            '368 restricted' => [368, null, 'integrity', 'ACCOUNT_RESTRICTED', 'none', true, 'channel'],
            '131048 spam' => [131048, null, 'integrity', 'SPAM_LIMITED', 'none', true, 'channel'],
            '131026 unreachable' => [131026, null, 'caller', 'RECIPIENT_UNREACHABLE', 'none', false, 'recipient'],
            '131049 marketing limit' => [131049, null, 'quality', 'MARKETING_LIMITED', 'none', false, 'recipient'],
            '131050 opted out' => [131050, null, 'quality', 'MARKETING_OPTED_OUT', 'after_24h', false, 'recipient'],
            '100 proof' => [100, '(#100) Invalid appsecret_proof provided in the API argument', 'auth', 'APPSECRET_PROOF_INVALID', 'none', true, 'channel'],
            '100 parameter' => [100, '(#100) Invalid parameter', 'caller', 'BAD_REQUEST', 'none', false, 'message'],
            '250 permission range' => [250, null, 'auth', 'PERMISSION_DENIED', 'none', true, 'channel'],
            '132001 template' => [132001, null, 'caller', 'TEMPLATE_ERROR', 'none', false, 'message'],
            '999999 unknown' => [999999, null, 'unknown', 'META_ERROR', 'none', false, 'message'],
        ];
    }

    #[DataProvider('documented')]
    public function test_each_documented_code_has_its_category_code_and_policy(int $code, ?string $message, string $category, string $internal, string $retry, bool $alert, string $scope): void
    {
        $e = MetaErrorCatalog::classify($code, $message);

        $this->assertSame([$category, $internal, $retry, $alert, $scope], [$e['category'], $e['code'], $e['retry'], $e['alert'], $e['scope']]);
        $this->assertNotSame('', $e['message']);
        $this->assertNotSame('', $e['action']);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]{4,} [A-Za-z]{4,} [A-Za-z]{4,}/u', $e['message'], 'الرسالة عربية لا نص ميتا');
    }

    public function test_unknown_code_names_itself_in_arabic(): void
    {
        $this->assertSame('رفضت ميتا الرسالة (رمز 999999). أُبلغ المسؤول.', MetaErrorCatalog::classify(999999)['message']);
    }

    public function test_never_retry_codes_and_every_auth_code_are_not_retryable(): void
    {
        foreach ([...MetaErrorCatalog::NEVER_RETRY, 0, 190, 3, 10, 131005, 133010, 131042, 250] as $code) {
            $e = MetaError::fromStatusError(['code' => $code]);
            $this->assertFalse($e->isRetryable(), "الرمز {$code}");
        }
        $this->assertTrue(MetaError::fromStatusError(['code' => 131000])->isRetryable());
        $this->assertTrue(MetaError::fromStatusError(['code' => 130429])->isRetryable());
    }

    public function test_details_are_cut_and_digit_runs_are_masked(): void
    {
        $masked = MetaErrorCatalog::maskDetails('Recipient 962791234567 is not a valid WhatsApp user '.str_repeat('x', 400));

        $this->assertStringNotContainsString('962791234567', $masked);
        $this->assertStringContainsString('•••', $masked);
        $this->assertLessThanOrEqual(300, mb_strlen($masked));
        // رجعي لعلة trim بقائمة محارف: نص عربي ينتهي بـ «ن» يبقى كما هو
        $this->assertSame('رقم العميل غير مسجّل في واتساب الآن', MetaErrorCatalog::maskDetails('رقم العميل غير مسجّل في واتساب الآن'));
        $this->assertNull(MetaErrorCatalog::maskDetails(''));
    }

    public function test_graph_response_shapes(): void
    {
        Http::fake([
            'a.test/*' => Http::response(['error' => ['code' => 190, 'type' => 'OAuthException', 'message' => 'Session has expired', 'fbtrace_id' => 'Ax1', 'error_data' => ['details' => 'user 16505551234']]], 401),
            'b.test/*' => Http::response('<html>Bad Gateway</html>', 502),
            'c.test/*' => Http::response(['error' => ['code' => 777, 'message' => 'x']], 503),
            'd.test/*' => Http::response('', 400),
        ]);

        $auth = MetaError::fromGraphResponse(Http::get('https://a.test/x'));
        $this->assertSame([190, 401, 'Ax1', 'OAuthException', 'TOKEN_INVALID', 'auth'], [$auth->metaCode, $auth->httpStatus, $auth->fbtraceId, $auth->type, $auth->code, $auth->category]);
        $this->assertSame('user •••', $auth->details);
        $this->assertArrayNotHasKey('details', $auth->toArray());
        $this->assertStringNotContainsString('OAuth', json_encode($auth->toArray(), JSON_UNESCAPED_UNICODE));

        // 5xx بلا JSON: ربما قبلتها ميتا، فلا إعادة (E19)
        $this->assertSame(['HTTP_5XX_NO_BODY', 'transient', 'none'], [($e = MetaError::fromGraphResponse(Http::get('https://b.test/x')))->code, $e->category, $e->retry]);
        $this->assertSame(['META_TRANSIENT', 'transient', 777], [($e = MetaError::fromGraphResponse(Http::get('https://c.test/x')))->code, $e->category, $e->metaCode]);
        $this->assertSame(['META_ERROR', 'unknown'], [($e = MetaError::fromGraphResponse(Http::get('https://d.test/x')))->code, $e->category]);
    }

    public function test_network_kinds(): void
    {
        $connect = new ConnectionException('cURL error 7: Failed to connect to graph.facebook.com port 443');
        $handshake = new ConnectionException('cURL error 28: Connection timed out after 10001 milliseconds');
        $timeout = new ConnectionException('cURL error 28: Operation timed out after 25000 milliseconds with 0 bytes received');

        $this->assertSame('HTTP_CONNECT_FAILED', MetaError::networkKind($connect));
        $this->assertSame('HTTP_CONNECT_FAILED', MetaError::networkKind($handshake));
        $this->assertSame('HTTP_TIMEOUT', MetaError::networkKind($timeout));
        $this->assertSame('HTTP_NO_RESPONSE', MetaError::networkKind(new \Illuminate\Http\Client\ConnectionException('cURL error 56: Recv failure: Connection reset by peer')), 'بعد الاتصال: ربما خرج الطلب');
        $this->assertTrue(MetaError::network('HTTP_CONNECT_FAILED')->isRetryable());
        $this->assertFalse(MetaError::network('HTTP_TIMEOUT')->isRetryable(), 'قد تكون وصلت: لا إعادة عمياء');
    }

    public function test_stored_codes_resolve_back_to_their_meaning(): void
    {
        $this->assertSame('auth', MetaErrorCatalog::byCode('TOKEN_INVALID')['category']);
        $this->assertSame('caller', MetaErrorCatalog::byCode('POLICY_SECRET')['category']);
        $this->assertSame('POLICY_SECRET', MetaErrorCatalog::byCode('POLICY_SECRET')['code']);
        $this->assertSame('window', MetaErrorCatalog::byCode('WINDOW_EXPIRED')['category']);
        $this->assertSame('transient', MetaErrorCatalog::byCode('HTTP_TIMEOUT')['category']);
        $this->assertNull(MetaErrorCatalog::byCode('HTTP_ERROR'));
        $this->assertSame('integrity', MetaErrorCatalog::forStored(368, 'ACCOUNT_RESTRICTED')['category']);
    }

    public function test_every_internal_code_fits_the_column(): void
    {
        $codes = [...array_column(MetaErrorCatalog::CODES, 0), ...array_keys(MetaErrorCatalog::LOCAL), 'TEMPLATE_ERROR', 'META_ERROR'];
        foreach ($codes as $code) {
            $this->assertLessThanOrEqual(32, strlen($code), $code);
        }
        foreach (array_keys(MetaErrorCatalog::CATEGORIES) as $category) {
            $this->assertLessThanOrEqual(12, strlen($category), $category);
        }
    }

    private function assertCatalogReviewedFor(?string $version): void
    {
        $this->assertSame(MetaErrorCatalog::REVIEWED_FOR_VERSION, $version,
            'راجع جدول الرموز: ترقية graph_version تفرض مراجعة MetaErrorCatalog ثم تحديث REVIEWED_FOR_VERSION');
    }

    public function test_the_table_was_reviewed_for_the_configured_graph_version(): void
    {
        $this->assertCatalogReviewedFor(config('services.whatsapp.graph_version'));
    }

    public function test_the_version_guard_fails_on_an_unreviewed_version(): void
    {
        config(['services.whatsapp.graph_version' => 'v26.0']);

        try {
            $this->assertCatalogReviewedFor(config('services.whatsapp.graph_version'));
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('راجع جدول الرموز', $e->getMessage());

            return;
        }
        $this->fail('الحارس لم يفشل على إصدار غير مُراجَع');
    }
}
