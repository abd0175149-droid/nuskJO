<?php

namespace Tests\Feature\WhatsApp;

use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * تنزيل وسائط رسالة واردة على خطوتين:
 *   1) GET graph.facebook.com/{version}/{MEDIA_ID} يعيد رابطاً مؤقتاً وحجماً ونوعاً.
 *   2) GET على الرابط المؤقت (lookaside.fbsbx.com) بنفس التوكن.
 */
class DownloadMediaTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const MEDIA_ID = '1037543291543636';

    private const LOOKASIDE_URL = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1037543291543636&ext=1690244445&hash=ARfDHQhsMbXwAbCdEf';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
    }

    /** رد خطوة البيانات الوصفية كما في مرجع Retrieve Media URL */
    private function mediaMeta(array $overrides = []): array
    {
        return array_merge([
            'messaging_product' => 'whatsapp',
            'url' => self::LOOKASIDE_URL,
            'mime_type' => 'audio/ogg; codecs=opus',
            'sha256' => 'a3c1b6f1e7d2c4b5a6f7e8d9c0b1a2f3e4d5c6b7a8f9e0d1c2b3a4f5e6d7c8b9',
            'file_size' => '7744',
            'id' => self::MEDIA_ID,
        ], $overrides);
    }

    private function fakeMedia(array $meta = [], int $status = 200): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::response($this->mediaMeta($meta), $status)]);
    }

    public function test_downloads_in_two_steps_both_with_bearer_token(): void
    {
        $this->fakeMedia();

        $media = WhatsAppChannel::downloadMedia(self::MEDIA_ID);

        $this->assertSame(['bytes' => 'MEDIA-BYTES', 'mime' => 'audio/ogg; codecs=opus'], $media);

        Http::assertSentCount(2);
        [$lookup, $download] = Http::recorded()->map(fn (array $pair) => $pair[0])->all();

        $this->assertSame('GET', $lookup->method());
        $this->assertSame('https://graph.facebook.com/v25.0/'.self::MEDIA_ID, $lookup->url());
        $this->assertTrue($lookup->hasHeader('Authorization', 'Bearer test-token'));

        $this->assertSame('GET', $download->method());
        $this->assertSame(self::LOOKASIDE_URL, $download->url());
        $this->assertTrue($download->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_media_lookup_follows_configured_graph_version(): void
    {
        config(['services.whatsapp.graph_version' => 'v26.0']);
        $this->fakeMedia();

        $this->assertNotNull(WhatsAppChannel::downloadMedia(self::MEDIA_ID));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v26.0/'.self::MEDIA_ID);
    }

    public function test_declared_file_size_above_limit_stops_before_download(): void
    {
        $this->fakeMedia(['file_size' => 20_000_000]);

        $this->assertNull(WhatsAppChannel::downloadMedia(self::MEDIA_ID));

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'lookaside.fbsbx.com'));
    }

    public function test_declared_file_size_respects_custom_limit(): void
    {
        $this->fakeMedia(['file_size' => 2048]);

        $this->assertNull(WhatsAppChannel::downloadMedia(self::MEDIA_ID, maxBytes: 1024));

        Http::assertSentCount(1);
    }

    public function test_downloaded_body_above_limit_is_rejected(): void
    {
        $this->fakeMedia(['file_size' => 4]);

        $this->assertNull(WhatsAppChannel::downloadMedia(self::MEDIA_ID, maxBytes: 5));

        Http::assertSentCount(2);
    }

    public static function failedLookups(): array
    {
        return [
            'not found' => [404, ['error' => ['message' => 'Unsupported get request.', 'type' => 'GraphMethodException', 'code' => 100]]],
            'expired token' => [401, ['error' => ['message' => 'Error validating access token: Session has expired.', 'type' => 'OAuthException', 'code' => 190]]],
            'ok without url' => [200, ['messaging_product' => 'whatsapp', 'id' => self::MEDIA_ID]],
        ];
    }

    #[DataProvider('failedLookups')]
    public function test_failed_first_step_returns_null_without_download(int $status, array $body): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::response($body, $status)]);

        $this->assertNull(WhatsAppChannel::downloadMedia(self::MEDIA_ID));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v25.0/'.self::MEDIA_ID);
    }

    public function test_failed_second_step_returns_null(): void
    {
        $this->fakeGraph([
            'graph.facebook.com/*' => Http::response($this->mediaMeta()),
            'lookaside.fbsbx.com/*' => Http::response('', 404),
        ]);

        $this->assertNull(WhatsAppChannel::downloadMedia(self::MEDIA_ID));

        Http::assertSentCount(2);
    }

    public function test_missing_mime_type_defaults_to_ogg_audio(): void
    {
        $meta = $this->mediaMeta();
        unset($meta['mime_type']);
        $this->fakeGraph(['graph.facebook.com/*' => Http::response($meta)]);

        $media = WhatsAppChannel::downloadMedia(self::MEDIA_ID);

        $this->assertSame('audio/ogg', $media['mime']);
        Http::assertSentCount(2);
    }

    public function test_media_id_is_url_encoded_into_a_single_path_segment(): void
    {
        $this->fakeMedia();

        WhatsAppChannel::downloadMedia('../me/accounts');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v25.0/..%2Fme%2Faccounts');
    }

    public function test_without_token_nothing_is_requested(): void
    {
        $this->configureChannel(['wa_token' => null]);
        $this->fakeMedia();

        $this->assertNull(WhatsAppChannel::downloadMedia(self::MEDIA_ID));

        Http::assertNothingSent();
    }

    public function test_empty_media_id_requests_nothing(): void
    {
        $this->fakeMedia();

        $this->assertNull(WhatsAppChannel::downloadMedia(''));

        Http::assertNothingSent();
    }
}
