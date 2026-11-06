<?php

namespace Tests\Feature\WhatsApp\Media;

use App\Jobs\ArchiveWhatsAppMedia;
use App\Models\WaMessage;
use App\Services\WhatsApp\Media\MediaArchiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * وظيفة الأرشفة (E14.4، منذ 2026-11-06 من البوابة): GET /messages/{chatId}/{id}/media بمفتاح رقم الرسالة في كل محاولة،
 * والسقف، وsha256 تُحسب هنا، والنوع بالفحص لا بالتصريح، والمفتاح لا يظهر في أي سجل.
 */
class ArchiveJobTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const WAMID = 'false_16505551234@c.us_3EB0MEDIA1';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wa_media');
        $this->configureChannel();
        config(['whatsapp.openwa.url' => 'http://nuskjo-openwa:2785', 'whatsapp.transport' => 'openwa']);
    }

    public static function jpeg(int $bytes = 200_000): string
    {
        $head = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

        return $head.str_repeat("\x00", max(0, $bytes - strlen($head) - 2))."\xFF\xD9";
    }

    private function message(array $attrs = []): WaMessage
    {
        static $n = 0;
        $wamid = self::WAMID.(++$n);

        return WaMessage::factory()->inbound()->create($attrs + ['msg_type' => 'image', 'body' => '📷 صورة', 'wamid' => $wamid, 'media_id' => $wamid,
            'media_status' => 'pending', 'payload' => ['_openwa' => true, 'chatId' => '16505551234@c.us']]);
    }

    /** البوابة تعيد الملف (أو ردها) */
    private function fakeMedia(string $bytes, int $status = 200, array $headers = []): void
    {
        Http::fake(['*/media' => Http::response($bytes, $status, $headers + ['Content-Type' => 'image/jpeg'])]);
    }

    private function runJob(WaMessage $m, ?int $cap = null): ArchiveWhatsAppMedia
    {
        $job = (new ArchiveWhatsAppMedia($m->id, $cap))->withFakeQueueInteractions();
        $job->handle(app(MediaArchiver::class));

        return $job;
    }

    private function tmpLeft(): array
    {
        return glob(storage_path('app/wa-media/.tmp/*.part')) ?: [];
    }

    public function test_a_jpeg_is_stored_under_a_uuid_path_with_its_hash(): void
    {
        $bytes = self::jpeg();
        $this->fakeMedia($bytes);
        $m = $this->message(['media_sha256' => null]);

        $this->runJob($m)->assertNotReleased();

        $m->refresh();
        $this->assertSame(['stored', 'image/jpeg', strlen($bytes), null, hash('sha256', $bytes)],
            [$m->media_status, $m->media_mime, $m->media_size, $m->media_error, $m->media_sha256]);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f-]{36}\.jpg$#', $m->media_path);
        $this->assertSame($bytes, Storage::disk('wa_media')->get($m->media_path));
        $this->assertNotNull($m->media_archived_at);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_ends_with($r->url(), '/sessions/sess-1/messages/'.rawurlencode('16505551234@c.us').'/'.rawurlencode($m->wamid).'/media')
            && $r->hasHeader('X-API-Key', 'key-1'));
        $this->assertSame([], $this->tmpLeft());
    }

    public function test_a_pdf_is_stored_by_its_detected_type(): void
    {
        $bytes = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $this->fakeMedia($bytes, headers: ['Content-Type' => 'application/octet-stream']);
        $m = $this->message(['msg_type' => 'document']);

        $this->runJob($m);

        $m->refresh();
        $this->assertSame(['stored', 'application/pdf', null], [$m->media_status, $m->media_mime, $m->media_error]);
        $this->assertStringEndsWith('.pdf', $m->media_path);
    }

    public function test_a_file_over_the_cap_is_never_stored(): void
    {
        $this->fakeMedia(self::jpeg(5000));
        $m = $this->message();

        $this->runJob($m, 1000);

        $this->assertSame(['too_large', 1001, null], [$m->fresh()->media_status, $m->fresh()->media_size, $m->fresh()->media_path]);
        $this->assertSame([], Storage::disk('wa_media')->allFiles());
        $this->assertSame([], $this->tmpLeft());
    }

    public function test_svg_and_html_disguised_as_jpeg_are_rejected(): void
    {
        foreach (['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', "<!DOCTYPE html>\n<html><body><script>alert(1)</script></body></html>"] as $bytes) {
            $this->fakeMedia($bytes);
            $m = $this->message(['media_sha256' => null]);

            $this->runJob($m);

            $m->refresh();
            $this->assertSame('rejected', $m->media_status);
            $this->assertMatchesRegularExpression('/^MIME_[0-9a-f]{6}$/', $m->media_error);
            $this->assertNull($m->media_path);
        }
        $this->assertSame([], Storage::disk('wa_media')->allFiles());
        $this->assertSame([], $this->tmpLeft());
    }

    public function test_media_gone_from_the_phone_expires_without_retry(): void
    {
        $this->fakeMedia('{"message":"Media not found"}', 404, ['Content-Type' => 'application/json']);
        $m = $this->message();

        $this->runJob($m)->assertNotReleased();

        $this->assertSame(['expired', 'MEDIA_EXPIRED'], [$m->fresh()->media_status, $m->fresh()->media_error]);
    }

    public function test_an_unreachable_gateway_releases_and_every_attempt_asks_again(): void
    {
        $bytes = self::jpeg();
        Http::fake(['*/media' => Http::sequence()
            ->push(['message' => 'Session not ready'], 503)
            ->push($bytes, 200, ['Content-Type' => 'image/jpeg'])]);
        $m = $this->message(['media_sha256' => null]);

        $this->runJob($m)->assertReleased(60);
        $this->assertSame('pending', $m->fresh()->media_status);
        $this->runJob($m);

        $this->assertSame('stored', $m->fresh()->media_status);
        $this->assertSame(2, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/media'))->count());
    }

    public function test_it_is_idempotent(): void
    {
        $this->fakeMedia(self::jpeg());
        $m = $this->message(['media_sha256' => null]);
        $this->runJob($m);
        $path = $m->fresh()->media_path;

        $this->runJob($m->fresh());

        $this->assertSame(1, Http::recorded()->count(), 'المحاولة الثانية لا تطلب شيئاً');
        $this->assertSame([$path], Storage::disk('wa_media')->allFiles());
    }

    public function test_the_api_key_never_reaches_a_log_or_failed_jobs(): void
    {
        $lines = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$lines) {
            $lines[] = $e->message.' '.json_encode($e->context);
        });
        Http::fake(['*/media' => fn () => throw new ConnectionException('cURL error 28: timed out (X-API-Key: key-1)')]);
        $m = $this->message();

        $this->runJob($m)->assertReleased();
        (new ArchiveWhatsAppMedia($m->id))->failed(new MaxAttemptsExceededException('too many'));

        $this->assertSame(['failed', 'RETRY_EXPIRED'], [$m->fresh()->media_status, $m->fresh()->media_error]);
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('key-1', $line);
        }
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_message_erased_during_the_download_leaves_no_file(): void
    {
        $this->fakeMedia(self::jpeg());
        $m = $this->message(['media_sha256' => null]);
        $archiver = app(MediaArchiver::class);
        $result = $archiver->archive($m, 25 * 1024 * 1024);
        $m->delete();

        $archiver->apply($m, $result);

        $this->assertSame([], Storage::disk('wa_media')->allFiles());
    }
}
