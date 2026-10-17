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
 * وظيفة الأرشفة (E14.4): GET /{media-id} جديد في كل محاولة، والسقف قبل التنزيل وأثناءه وبعده، وsha256 بصيغتيه،
 * والنوع بالفحص لا بالتصريح، والرمز لا يذهب لمضيف غريب، ولا يظهر الرابط في أي سجل.
 */
class ArchiveJobTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const MEDIA_ID = '1104480873777230';

    private const URL = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1104480873777230&ext=1760425300&hash=SECRETHASH';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wa_media');
        $this->configureChannel();
    }

    public static function jpeg(int $bytes = 200_000): string
    {
        $head = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

        return $head.str_repeat("\x00", max(0, $bytes - strlen($head) - 2))."\xFF\xD9";
    }

    private function message(array $attrs = []): WaMessage
    {
        return WaMessage::factory()->inbound()->create($attrs + ['msg_type' => 'image', 'body' => '📷 صورة', 'media_id' => self::MEDIA_ID, 'media_status' => 'pending']);
    }

    /** ردود ميتا: بيانات الوسيط ثم الملف */
    private function fakeMeta(string $bytes, array $meta = [], array $headers = [], ?string $url = null): void
    {
        $url ??= self::URL;
        Http::fake([
            'graph.facebook.com/*' => Http::response($meta + ['url' => $url, 'mime_type' => 'image/jpeg', 'sha256' => hash('sha256', $bytes), 'file_size' => strlen($bytes), 'id' => self::MEDIA_ID]),
            '*' => Http::response($bytes, 200, $headers + ['Content-Type' => 'image/jpeg']),
        ]);
    }

    private function runJob(WaMessage $m, ?int $cap = null): ArchiveWhatsAppMedia
    {
        $job = (new ArchiveWhatsAppMedia($m->id, $cap))->withFakeQueueInteractions();
        $job->handle(app(MediaArchiver::class));

        return $job;
    }

    private function downloads(): int
    {
        return Http::recorded(fn (Request $r) => !str_contains($r->url(), 'graph.facebook.com'))->count();
    }

    private function tmpLeft(): array
    {
        return glob(storage_path('app/wa-media/.tmp/*.part')) ?: [];
    }

    public function test_a_jpeg_with_a_hex_hash_is_stored_under_a_uuid_path(): void
    {
        $bytes = self::jpeg();
        $this->fakeMeta($bytes);
        $m = $this->message(['media_sha256' => null]);

        $this->runJob($m)->assertNotReleased();

        $m->refresh();
        $this->assertSame(['stored', 'image/jpeg', strlen($bytes), null], [$m->media_status, $m->media_mime, $m->media_size, $m->media_error]);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f-]{36}\.jpg$#', $m->media_path);
        $this->assertSame($bytes, Storage::disk('wa_media')->get($m->media_path));
        $this->assertNotNull($m->media_archived_at);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com') && str_contains($r->url(), self::MEDIA_ID) && $r->hasHeader('Authorization'));
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://lookaside.fbsbx.com/') && $r->hasHeader('Authorization', 'Bearer test-token'));
        $this->assertSame([], $this->tmpLeft());
    }

    public function test_a_base64_hash_from_the_webhook_also_matches(): void
    {
        $bytes = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $this->fakeMeta($bytes, ['sha256' => 'ignored-when-the-webhook-has-one', 'mime_type' => 'application/pdf']);
        $m = $this->message(['msg_type' => 'document', 'media_sha256' => base64_encode(hash('sha256', $bytes, true))]);

        $this->runJob($m);

        $m->refresh();
        $this->assertSame(['stored', 'application/pdf', null], [$m->media_status, $m->media_mime, $m->media_error]);
        $this->assertStringEndsWith('.pdf', $m->media_path);
    }

    public function test_a_file_over_the_cap_is_never_downloaded(): void
    {
        $this->fakeMeta('x', ['file_size' => 26 * 1024 * 1024]);
        $m = $this->message();

        $this->runJob($m);

        $this->assertSame(['too_large', 26 * 1024 * 1024], [$m->fresh()->media_status, $m->fresh()->media_size]);
        $this->assertSame(0, $this->downloads());
    }

    public function test_a_large_content_length_stops_the_download(): void
    {
        $this->fakeMeta(self::jpeg(1000), ['file_size' => 1000], ['Content-Length' => (string) (30 * 1024 * 1024)]);
        $m = $this->message();

        $this->runJob($m);

        $this->assertSame('too_large', $m->fresh()->media_status);
        $this->assertNull($m->fresh()->media_path);
        $this->assertSame([], Storage::disk('wa_media')->allFiles());
        $this->assertSame([], $this->tmpLeft());
    }

    public function test_svg_and_html_disguised_as_jpeg_are_rejected(): void
    {
        foreach (['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', "<!DOCTYPE html>\n<html><body><script>alert(1)</script></body></html>"] as $bytes) {
            $this->fakeMeta($bytes);
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

    public function test_a_hash_mismatch_is_kept_in_log_mode_and_fails_in_enforce_mode(): void
    {
        $this->fakeMeta(self::jpeg(), ['sha256' => str_repeat('0', 64)]);
        $logged = $this->message(['media_sha256' => null]);
        $this->runJob($logged);
        $this->assertSame(['stored', 'sha_mismatch'], [$logged->fresh()->media_status, $logged->fresh()->media_error]);

        config(['whatsapp.media.sha_mode' => 'enforce']);
        $enforced = $this->message(['media_sha256' => null]);
        $this->runJob($enforced);
        $this->assertSame(['failed', 'MEDIA_INTEGRITY', null], [$enforced->fresh()->media_status, $enforced->fresh()->media_error, $enforced->fresh()->media_path]);
    }

    public function test_the_token_never_goes_to_a_host_outside_the_list(): void
    {
        $this->fakeMeta(self::jpeg(), url: 'https://cdn.example.net/file.jpg');
        $m = $this->message();

        $this->runJob($m);

        $this->assertSame(['failed', 'MEDIA_HOST_BLOCKED'], [$m->fresh()->media_status, $m->fresh()->media_error]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'example.net'));
    }

    public function test_a_missing_media_id_expires_without_retry(): void
    {
        foreach ([[404, 100], [400, 100]] as [$http, $code]) {
            Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => $code, 'message' => 'Unsupported get request. Object does not exist']], $http)]);
            $m = $this->message();

            $this->runJob($m)->assertNotReleased();

            $this->assertSame(['expired', 'META_NOT_FOUND'], [$m->fresh()->media_status, $m->fresh()->media_error]);
        }
    }

    public function test_throttling_releases_and_every_attempt_asks_meta_for_a_fresh_url(): void
    {
        $bytes = self::jpeg();
        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['error' => ['code' => 130429, 'message' => 'Rate limit hit']], 429)
                ->push(['url' => self::URL, 'mime_type' => 'image/jpeg', 'sha256' => hash('sha256', $bytes), 'file_size' => strlen($bytes)]),
            '*' => Http::response($bytes, 200),
        ]);
        $m = $this->message(['media_sha256' => null]);

        $this->runJob($m)->assertReleased(60);
        $this->assertSame('pending', $m->fresh()->media_status);
        $this->runJob($m);

        $this->assertSame('stored', $m->fresh()->media_status);
        $this->assertSame(2, Http::recorded(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com'))->count());
    }

    public function test_it_is_idempotent(): void
    {
        $this->fakeMeta(self::jpeg());
        $m = $this->message(['media_sha256' => null]);
        $this->runJob($m);
        $path = $m->fresh()->media_path;

        $this->runJob($m->fresh());

        $this->assertSame(2, Http::recorded()->count(), 'المحاولة الثانية لا تطلب شيئاً');
        $this->assertSame([$path], Storage::disk('wa_media')->allFiles());
    }

    public function test_the_meta_url_never_reaches_a_log_or_failed_jobs(): void
    {
        $lines = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$lines) {
            $lines[] = $e->message.' '.json_encode($e->context);
        });
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => self::URL, 'mime_type' => 'image/jpeg', 'sha256' => str_repeat('a', 64), 'file_size' => 10]),
            '*' => fn () => throw new ConnectionException('cURL error 28: timed out for '.self::URL),
        ]);
        $m = $this->message();

        $this->runJob($m)->assertReleased();
        (new ArchiveWhatsAppMedia($m->id))->failed(new MaxAttemptsExceededException('too many'));

        $this->assertSame(['failed', 'RETRY_EXPIRED'], [$m->fresh()->media_status, $m->fresh()->media_error]);
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('SECRETHASH', $line);
            $this->assertStringNotContainsString('lookaside', $line);
        }
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_message_erased_during_the_download_leaves_no_file(): void
    {
        $this->fakeMeta(self::jpeg());
        $m = $this->message(['media_sha256' => null]);
        $archiver = app(MediaArchiver::class);
        $result = $archiver->archive($m, 25 * 1024 * 1024);
        $m->delete();

        $archiver->apply($m, $result);

        $this->assertSame([], Storage::disk('wa_media')->allFiles());
    }
}
