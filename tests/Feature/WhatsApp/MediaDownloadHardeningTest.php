<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\VoiceTranscriber;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * تنزيل الوسائط: https إلزامي، ومضيف من قائمة ميتا (تسجيل ثم رفض)، والبصمة sha256،
 * والسقف أثناء القراءة، ونوع الصوت من قائمة.
 */
class MediaDownloadHardeningTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const MEDIA_ID = '1037543291543636';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
    }

    private function fakeMedia(string $url, array $meta = [], ?string $body = 'MEDIA-BYTES'): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        Http::fake([
            'graph.facebook.com/*' => Http::response(array_merge([
                'url' => $url, 'mime_type' => 'audio/ogg; codecs=opus', 'sha256' => hash('sha256', 'MEDIA-BYTES'),
                'file_size' => '11', 'id' => self::MEDIA_ID,
            ], $meta)),
            $host.'/*' => Http::response($body),
        ]);
    }

    public function test_plain_http_media_url_never_receives_the_token(): void
    {
        $this->fakeMedia('http://lookaside.fbsbx.com/x');

        $this->assertSame(WhatsAppChannel::ERR_MEDIA_HOST, WhatsAppChannel::fetchMedia(self::MEDIA_ID)['code']);
        Http::assertSentCount(1);
    }

    public function test_unknown_host_is_logged_in_log_mode_and_refused_in_enforce_mode(): void
    {
        $this->captureLogs();
        $this->fakeMedia('https://cdn.example.org/x');

        $this->assertTrue(WhatsAppChannel::fetchMedia(self::MEDIA_ID)['ok']);
        $this->assertLogged('error', 'media.host.outside_allowlist', ['host' => 'cdn.example.org']);

        config(['services.whatsapp.media_host_mode' => 'enforce']);
        $this->assertSame(WhatsAppChannel::ERR_MEDIA_HOST, WhatsAppChannel::fetchMedia(self::MEDIA_ID)['code']);
        Http::assertSentCount(3);   // بحث + تنزيل في وضع التسجيل، ثم بحث وحده
    }

    public function test_allowlist_accepts_subdomains_of_meta_hosts(): void
    {
        config(['services.whatsapp.media_host_mode' => 'enforce']);
        $this->fakeMedia('https://mmg.whatsapp.net/v/t62/x');

        $this->assertTrue(WhatsAppChannel::fetchMedia(self::MEDIA_ID)['ok']);
    }

    public function test_allowlist_rejects_lookalike_hosts(): void
    {
        config(['services.whatsapp.media_host_mode' => 'enforce']);
        $this->fakeMedia('https://evilwhatsapp.net/x');

        $this->assertSame(WhatsAppChannel::ERR_MEDIA_HOST, WhatsAppChannel::fetchMedia(self::MEDIA_ID)['code']);
    }

    public function test_hash_mismatch_is_logged_in_log_mode_and_refused_in_enforce_mode(): void
    {
        $this->fakeMedia('https://lookaside.fbsbx.com/x', ['sha256' => str_repeat('a', 64)]);

        $this->assertTrue(WhatsAppChannel::fetchMedia(self::MEDIA_ID)['ok']);

        config(['services.whatsapp.media_host_mode' => 'enforce']);
        $this->assertSame(WhatsAppChannel::ERR_MEDIA_INTEGRITY, WhatsAppChannel::fetchMedia(self::MEDIA_ID)['code']);
    }

    public function test_body_is_capped_while_reading_even_without_a_declared_size(): void
    {
        $this->fakeMedia('https://lookaside.fbsbx.com/x', ['file_size' => null], str_repeat('x', 200_000));

        $this->assertSame(WhatsAppChannel::ERR_TOO_LARGE, WhatsAppChannel::fetchMedia(self::MEDIA_ID, 'audio', 100_000)['code']);
    }

    public function test_only_audio_types_are_downloaded(): void
    {
        $this->fakeMedia('https://lookaside.fbsbx.com/x', ['mime_type' => 'application/pdf']);

        $this->assertSame(WhatsAppChannel::ERR_UNSUPPORTED, WhatsAppChannel::fetchMedia(self::MEDIA_ID)['code']);
        $this->assertSame(WhatsAppChannel::ERR_UNSUPPORTED, WhatsAppChannel::fetchMedia(self::MEDIA_ID, 'image')['code']);
        Http::assertSentCount(1);
    }

    public function test_enforce_mode_does_not_follow_a_redirect_from_the_media_host(): void
    {
        config(['services.whatsapp.media_host_mode' => 'enforce']);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'audio/ogg', 'sha256' => hash('sha256', 'MEDIA-BYTES')]),
            'lookaside.fbsbx.com/*' => Http::response('', 302, ['Location' => 'https://collector.example/x']),
            'collector.example/*' => Http::response('MEDIA-BYTES'),
        ]);

        $this->assertFalse(WhatsAppChannel::fetchMedia(self::MEDIA_ID)['ok']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'collector.example'));
    }

    public function test_log_mode_follows_one_https_redirect_and_records_its_host(): void
    {
        $this->captureLogs();
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'audio/ogg', 'sha256' => hash('sha256', 'MEDIA-BYTES')]),
            'lookaside.fbsbx.com/*' => Http::response('', 302, ['Location' => 'https://mmg.whatsapp.net/y']),
            'mmg.whatsapp.net/*' => Http::response('MEDIA-BYTES'),
        ]);

        $this->assertTrue(WhatsAppChannel::fetchMedia(self::MEDIA_ID)['ok']);
        $this->assertLogged('error', 'media.download.redirected', ['host' => 'mmg.whatsapp.net']);
    }

    public function test_voice_message_is_transcribed_end_to_end_and_records_the_host(): void
    {
        $this->configureChannel(['provider' => 'google', 'enabled' => true]);
        $conv = WaConversation::factory()->windowOpen()->create();
        $voice = WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'msg_type' => 'audio', 'payload' => ['audio' => ['id' => self::MEDIA_ID]]]);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'audio/ogg; codecs=opus', 'sha256' => hash('sha256', 'MEDIA-BYTES')]),
            'lookaside.fbsbx.com/*' => Http::response('MEDIA-BYTES'),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'أريد عمرة']]]]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 3]]),
        ]);

        VoiceTranscriber::run($conv);

        $voice->refresh();
        $this->assertSame('🎤 أريد عمرة', $voice->body);
        $this->assertSame('lookaside.fbsbx.com', $voice->payload['_media_host']);
        $this->assertSame(1, WaBotUsage::where('source', 'voice')->count());
    }

    public function test_voice_with_a_provider_that_cannot_hear_records_unsupported(): void
    {
        $this->configureChannel(['provider' => 'anthropic', 'enabled' => true]);
        $conv = WaConversation::factory()->windowOpen()->create();
        $voice = WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'msg_type' => 'audio', 'payload' => ['audio' => ['id' => self::MEDIA_ID]]]);
        Http::fake();

        VoiceTranscriber::run($conv);

        $this->assertSame(WhatsAppChannel::ERR_UNSUPPORTED, $voice->fresh()->payload['_media_error']);
        Http::assertNothingSent();
    }

    public function test_voice_message_records_the_media_host_or_the_error_code(): void
    {
        $this->configureChannel(['provider' => 'google', 'enabled' => true]);
        $conv = WaConversation::factory()->windowOpen()->create();
        $voice = WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'msg_type' => 'audio', 'payload' => ['audio' => ['id' => self::MEDIA_ID]]]);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'application/pdf']),
        ]);

        VoiceTranscriber::run($conv);

        $voice->refresh();
        $this->assertSame(WhatsAppChannel::ERR_UNSUPPORTED, $voice->payload['_media_error']);
        $this->assertSame(VoiceTranscriber::FAILED_BODY, $voice->body);
    }
}
