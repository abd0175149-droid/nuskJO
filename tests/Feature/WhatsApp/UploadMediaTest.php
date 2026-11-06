<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendWhatsAppMediaJob;
use App\Models\WaConversation;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsMediaSamples;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * «رفع» الملف قبل إرساله (E32.5، منذ 2026-11-06): البوابة تأخذ الملف base64 مع الرسالة نفسها، فلا رفع إلى طرف ثالث.
 * المعرّف «local:{مسار}» على قرص wa_media الخاص: الملف نفسه إن كان عليه، وإلا نسخة في openwa-staging/ باسم عشوائي
 * (لا اسم الملف). الأقفال قبل أي نسخ، ومهلة الإرسال بملف محسوبة من الحجم تحت مهلة الوظيفة.
 */
class UploadMediaTest extends TestCase
{
    use BuildsMediaSamples;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wa_media');
        $this->configureChannel();
        $this->fakeGateway();
        $this->conv = WaConversation::factory()->windowOpen()->create();
    }

    protected function tearDown(): void
    {
        $this->tearDownMediaSamples();
        parent::tearDown();
    }

    public function test_a_file_outside_the_private_disk_is_staged_under_a_random_name_without_any_request(): void
    {
        $bytes = $this->pdfBytes();
        $path = $this->sampleFile($bytes, 'pdf');

        $result = WhatsAppChannel::uploadMedia($this->conv, $path, 'application/pdf', 'جواز-أحمد.pdf', 'bot');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['code']);
        $this->assertMatchesRegularExpression('#^local:openwa-staging/\d{4}/\d{2}/[0-9a-f-]{36}\.pdf$#', $result['media_id']);
        $this->assertStringNotContainsString('جواز', $result['media_id']);
        $this->assertSame($bytes, Storage::disk('wa_media')->get(substr($result['media_id'], 6)));
        Http::assertNothingSent();
    }

    public function test_a_file_already_on_the_private_disk_is_used_in_place(): void
    {
        Storage::disk('wa_media')->put('outbound/2026/11/x.pdf', 'PDF');

        $result = WhatsAppChannel::uploadMedia($this->conv, Storage::disk('wa_media')->path('outbound/2026/11/x.pdf'), 'application/pdf', 'x.pdf', 'staff',
            \App\Models\User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create()->id);

        $this->assertSame(['ok' => true, 'media_id' => 'local:outbound/2026/11/x.pdf', 'code' => null, 'error' => null], $result);
        $this->assertSame([], Storage::disk('wa_media')->allFiles('openwa-staging'));
    }

    public function test_locks_refuse_before_any_copy(): void
    {
        $this->testChannel()->update(['paused_until' => now()->addHour()]);

        $result = WhatsAppChannel::uploadMedia($this->conv, $this->sampleFile($this->pdfBytes(), 'pdf'), 'application/pdf', 'x.pdf', 'bot');

        $this->assertSame([false, WhatsAppChannel::ERR_PAUSED, null], [$result['ok'], $result['code'], $result['media_id']]);
        $this->assertSame([], Storage::disk('wa_media')->allFiles());
    }

    public function test_a_missing_or_empty_file_is_refused(): void
    {
        $result = WhatsAppChannel::uploadMedia($this->conv, sys_get_temp_dir().'/nusk-missing-'.uniqid().'.pdf', 'application/pdf', 'x.pdf', 'bot');

        $this->assertSame([false, 'MEDIA_FILE_MISSING', null], [$result['ok'], $result['code'], $result['media_id']]);
        $this->assertNotEmpty($result['error']);
    }

    public function test_the_timeout_grows_with_size_and_stays_under_the_job_budget(): void
    {
        $this->assertSame(30, WhatsAppChannel::uploadTimeout(0));
        $this->assertSame(31, WhatsAppChannel::uploadTimeout(262144));
        $this->assertSame(85, WhatsAppChannel::uploadTimeout(16 * 1024 * 1024));
        // الإرسال بملف (85) ومهلة الاتصال تحت مهلة الوظيفة (120)
        $this->assertLessThan((new SendWhatsAppMediaJob(1))->timeout, WhatsAppChannel::uploadTimeout(PHP_INT_MAX >> 8) + 25 + 5);
    }
}
