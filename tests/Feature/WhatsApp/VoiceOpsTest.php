<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\MediaConverter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\FakesVoiceConverter;
use Tests\TestCase;

/**
 * تشغيل الرسائل الصوتية (E34.2/E34.9/E34.10): ffmpeg في الصورة وفي whatsapp:doctor، والميكروفون للأصل نفسه وحده،
 * ومعاينة blob في CSP، وكنس المجلد المؤقت كل ساعة.
 */
class VoiceOpsTest extends TestCase
{
    use ConfiguresWhatsApp;
    use FakesVoiceConverter;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->tearDownVoiceConverter();
        parent::tearDown();
    }

    public function test_the_image_installs_ffmpeg_in_production_and_for_the_test_gate(): void
    {
        foreach (['Dockerfile', 'Dockerfile.test'] as $file) {
            // تعليمة apk add كاملة: الأسطر المنتهية بـ \ وأسطر التعليق بينها، ثم السطر الأخير
            preg_match('/RUN apk add --no-cache(?:[^\n]*\\\\\n|[ \t]*#[^\n]*\n)*[^\n]*/', (string) file_get_contents(base_path($file)), $m);
            $packages = preg_replace('/^[ \t]*#.*$/m', '', $m[0] ?? '');
            $this->assertMatchesRegularExpression('/\sffmpeg(\s|$)/', $packages, $file);
        }
    }

    public function test_the_microphone_is_allowed_for_this_origin_only_and_blob_media_is_allowed(): void
    {
        $snippet = (string) file_get_contents(base_path('docker/nginx-security-headers.conf'));
        $this->assertMatchesRegularExpression('/add_header Permissions-Policy "camera=\(\), microphone=\(self\), geolocation=\(self\), payment=\(\), usb=\(\)" always;/', $snippet);

        config(['csp.mode' => 'enforce']);
        $policy = (string) $this->get('/login')->assertOk()->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("media-src 'self' blob:", $policy);
    }

    public function test_doctor_shows_ffmpeg_and_flags_it_missing_only_when_voice_notes_are_on(): void
    {
        $this->configureChannel();
        $this->fakeConverter();
        $this->artisan('whatsapp:doctor')->expectsOutputToContain('ffmpeg: متاح — ffmpeg version 6.1-test')->assertExitCode(0);

        MediaConverter::fake(null);
        config(['whatsapp.voice.ffmpeg_path' => '/nonexistent/ffmpeg']);
        $this->artisan('whatsapp:doctor')->expectsOutputToContain('ffmpeg: غير متاح')->assertExitCode(0);
        Artisan::call('whatsapp:doctor', ['--strict' => true]);
        $this->assertStringNotContainsString('FFMPEG_MISSING', Artisan::output());

        config(['features.env.staff_voice_notes' => true]);
        $this->assertSame(1, Artisan::call('whatsapp:doctor', ['--strict' => true]));
        $this->assertStringContainsString('FFMPEG_MISSING', Artisan::output());
    }

    public function test_the_sweep_removes_leftovers_older_than_an_hour_and_prints_the_count(): void
    {
        $old = MediaConverter::tmpPath('in');
        $fresh = MediaConverter::tmpPath('ogg');
        file_put_contents($old, 'x');
        file_put_contents($fresh, 'y');
        touch($old, time() - 3700);

        $this->artisan('whatsapp:voice-tmp-sweep')->expectsOutput('1')->assertExitCode(0);

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
        @unlink($fresh);
    }

    public function test_the_sweep_runs_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'whatsapp:voice-tmp-sweep'));

        $this->assertNotNull($event);
        $this->assertSame('35 * * * *', $event->expression);
    }

    public function test_the_owner_can_delete_played_voice_notes_from_meta_like_other_files(): void
    {
        $this->configureChannel();
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $conv = WaConversation::factory()->windowOpen()->create();
        $voice = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'msg_type' => 'audio', 'status' => 'played',
            'source' => 'staff', 'media_id' => '7731450912836654', 'media_path' => 'outbound/2026/10/v.ogg', 'media_status' => 'stored']);
        WaMessage::whereKey($voice->id)->update(['updated_at' => now()->subHours(2)]);

        // الافتراضي (المستندات بعد «قُرئت») لا يمس الصوت
        config(['features.env.outbound_media_remote_delete' => true]);
        Artisan::call('whatsapp:outbound-media-cleanup');
        Http::assertNothingSent();

        config(['whatsapp.outbound.remote_delete.types' => ['document', 'audio'], 'whatsapp.outbound.remote_delete.statuses' => ['read', 'played']]);
        Artisan::call('whatsapp:outbound-media-cleanup');

        $this->assertNotNull($voice->fresh()->media_remote_deleted_at);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '7731450912836654'));
    }

    public function test_the_temporary_folder_is_private(): void
    {
        $dir = MediaConverter::tmpDir();
        @rmdir($dir);
        $dir = MediaConverter::tmpDir();

        $this->assertSame('0700', substr(sprintf('%o', fileperms($dir)), -4));
    }
}
