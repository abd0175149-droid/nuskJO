<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendWhatsAppMediaJob;
use App\Models\WaConversation;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Tests\Concerns\BuildsMediaSamples;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الرفع إلى ميتا (E32.5): POST {pnid}/media متعدد الأجزاء بالحقول الرسمية والملف من مورد لا من نص، ومهلة محسوبة من
 * الحجم، وخطأ ميتا مصنّف بلا إعادة آلية، وسطر سجل بلا اسم الملف.
 */
class UploadMediaTest extends TestCase
{
    use BuildsMediaSamples;
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const MEDIA_URL = 'https://graph.facebook.com/v25.0/106540352242922/media';

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->conv = WaConversation::factory()->windowOpen()->create();
    }

    protected function tearDown(): void
    {
        $this->tearDownMediaSamples();
        parent::tearDown();
    }

    public function test_the_upload_is_multipart_with_the_official_fields_and_a_streamed_file(): void
    {
        $options = [];
        Http::fake(['graph.facebook.com/*' => function (Request $r, array $o) use (&$options) {
            $options = $o;

            return Http::response(['id' => '4490709327384033']);
        }]);
        $path = $this->sampleFile($this->pdfBytes(), 'pdf');

        $result = WhatsAppChannel::uploadMedia($this->conv, $path, 'application/pdf', 'فاتورة-7.pdf', 'bot');

        $this->assertSame(['ok' => true, 'media_id' => '4490709327384033', 'code' => null, 'error' => null], $result);
        Http::assertSent(function (Request $r) {
            $parts = collect($r->data())->keyBy('name');

            return $r->url() === self::MEDIA_URL && $r->method() === 'POST' && $r->isMultipart()
                && $r->hasHeader('Authorization', 'Bearer test-token')
                && $parts['messaging_product']['contents'] === 'whatsapp'
                && $parts['type']['contents'] === 'application/pdf'
                && $r->hasFile('file', null, 'فاتورة-7.pdf')
                // مورد مفتوح يقرؤه Guzzle، لا نص الملف في الذاكرة
                && (is_resource($parts['file']['contents']) || $parts['file']['contents'] instanceof StreamInterface);
        });
        // ملف صغير: 30 ثانية (وثانية لكل 256KB فوقها)
        $this->assertSame(30, $options['timeout']);
        Http::assertSentCount(1);
    }

    public function test_the_timeout_grows_with_size_and_stays_under_the_job_budget(): void
    {
        $this->assertSame(30, WhatsAppChannel::uploadTimeout(0));
        $this->assertSame(31, WhatsAppChannel::uploadTimeout(262144));
        $this->assertSame(85, WhatsAppChannel::uploadTimeout(16 * 1024 * 1024));
        // الرفع (85) والإرسال (25) ومهلة الاتصال تحت مهلة الوظيفة (120)
        $this->assertLessThan((new SendWhatsAppMediaJob(1))->timeout, WhatsAppChannel::uploadTimeout(PHP_INT_MAX >> 8) + 25 + 5);
    }

    public function test_a_meta_rejection_is_classified_and_not_retried(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131053, 'message' => 'Media upload error', 'fbtrace_id' => 'AbCdTrace']], 400)]);
        config(['features.env.meta_error_v2' => true, 'features.env.send_retry' => true]);

        $result = WhatsAppChannel::uploadMedia($this->conv, $this->sampleFile($this->pdfBytes(), 'pdf'), 'application/pdf', 'x.pdf', 'bot');

        $this->assertFalse($result['ok']);
        $this->assertSame([131053, 'AbCdTrace', null], [$result['meta_code'], $result['fbtrace_id'], $result['media_id']]);
        $this->assertNotEmpty($result['error']);
        Http::assertSentCount(1);
    }

    public function test_a_connection_failure_is_one_attempt_and_says_nothing_was_sent(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::failedConnection('cURL error 7: Failed to connect')]);

        $result = WhatsAppChannel::uploadMedia($this->conv, $this->sampleFile($this->pdfBytes(), 'pdf'), 'application/pdf', 'x.pdf', 'bot');

        $this->assertSame([false, 'HTTP_CONNECT_FAILED'], [$result['ok'], $result['code']]);
        $this->assertStringContainsString('لم يُرسل', $result['error']);
        Http::assertSentCount(1);
    }

    public function test_the_log_line_carries_size_and_type_but_never_the_file_name(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);
        $this->captureLogs();

        WhatsAppChannel::uploadMedia($this->conv, $this->sampleFile($this->pdfBytes(), 'pdf'), 'application/pdf', 'جواز-أحمد.pdf', 'bot');

        $line = collect($this->logged)->firstWhere('message', 'wa.media.uploaded');
        $this->assertNotNull($line);
        $this->assertSame('application/pdf', $line['context']['mime']);
        $this->assertArrayHasKey('bytes', $line['context']);
        $this->assertStringNotContainsString('جواز', json_encode($this->logged, JSON_UNESCAPED_UNICODE));
    }

    public function test_appsecret_proof_rides_on_the_upload_too(): void
    {
        config(['services.whatsapp.appsecret_proof' => true]);
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);

        WhatsAppChannel::uploadMedia($this->conv, $this->sampleFile($this->pdfBytes(), 'pdf'), 'application/pdf', 'x.pdf', 'bot');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'appsecret_proof='.hash_hmac('sha256', 'test-token', self::TEST_APP_SECRET)));
    }
}
