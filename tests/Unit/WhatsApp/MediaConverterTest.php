<?php

namespace Tests\Unit\WhatsApp;

use App\Exceptions\ConverterBusyException;
use App\Exceptions\VoiceConversionException;
use App\Exceptions\VoiceTooLongException;
use App\Services\WhatsApp\MediaConverter;
use App\Services\WhatsApp\VoiceNoteValidator;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\FakesVoiceConverter;
use Tests\TestCase;

/**
 * المحوّل (E34.3): ogg/opus أحادي بوسائط مصفوفة وبيئة PATH وحدها، وحد المدة، وإعادة الترميز مرة فوق 512KB، وقفل يمنع
 * تحويلين معاً، ومهلة، ولا ملف مؤقت يبقى بعد نجاح أو فشل. أغلبها بمحوّل مزيّف؛ ومجموعة ffmpeg تعمل حيث يوجد فعلاً.
 */
class MediaConverterTest extends TestCase
{
    use CapturesLogs;
    use FakesVoiceConverter;

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.voice.lock_wait' => 0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownVoiceConverter();
        parent::tearDown();
    }

    private function input(string $ext = 'in', string $bytes = 'recording'): string
    {
        $path = MediaConverter::tmpPath($ext);
        file_put_contents($path, $bytes);

        return $path;
    }

    private function leftovers(): array
    {
        return glob(MediaConverter::tmpDir().'/*') ?: [];
    }

    public function test_a_recording_becomes_mono_ogg_opus_with_array_arguments_and_the_input_is_removed(): void
    {
        $this->fakeConverter(inputSeconds: 'N/A', outputSeconds: 42.4);
        $in = $this->input();

        $out = MediaConverter::toVoice($in);

        $this->assertFileDoesNotExist($in);
        $this->assertFileExists($out['path']);
        $this->assertMatchesRegularExpression('#/tmp/voice/[0-9a-f-]{36}\.ogg$#', $out['path']);
        $this->assertSame([42.4, filesize($out['path']), hash_file('sha256', $out['path'])], [$out['seconds'], $out['bytes'], $out['sha256']]);
        $this->assertNull(VoiceNoteValidator::check($out['path']));

        [$encode] = $this->encodeCalls();
        $this->assertSame(['-nostdin', '-i', $in, '-t', '126', '-vn', '-map_metadata', '-1', '-ac', '1', '-ar', '48000', '-c:a', 'libopus', '-b:a', '32k', '-application', 'voip', '-f', 'ogg', $out['path']],
            array_values(array_intersect($encode, ['-nostdin', '-i', $in, '-t', '126', '-vn', '-map_metadata', '-1', '-ac', '1', '-ar', '48000', '-c:a', 'libopus', '-b:a', '32k', '-application', 'voip', '-f', 'ogg', $out['path']])));
        // ffprobe للتسجيل ثم ffmpeg ثم ffprobe للناتج، كلها مصفوفات
        $this->assertCount(3, $this->converterCalls);
        @unlink($out['path']);
    }

    public function test_a_name_with_shell_characters_stays_one_argument(): void
    {
        $this->fakeConverter();
        $in = MediaConverter::tmpDir().'/a; touch pwned $(id).in';
        file_put_contents($in, 'x');

        $out = MediaConverter::toVoice($in);

        // ffprobe للتسجيل وffmpeg: الاسم وسيط واحد كما هو
        $this->assertContains($in, $this->converterCalls[0]);
        $this->assertContains($in, $this->encodeCalls()[0]);
        $this->assertFileDoesNotExist($in);
        @unlink($out['path']);
    }

    public function test_the_process_environment_is_path_only(): void
    {
        $_ENV['WA_TEST_SECRET_E34'] = 'not-for-ffmpeg';
        try {
            $env = MediaConverter::processEnv();
        } finally {
            unset($_ENV['WA_TEST_SECRET_E34']);
        }

        $this->assertSame(['PATH'], array_keys(array_filter($env, fn ($v) => $v !== false)));
        $this->assertFalse($env['WA_TEST_SECRET_E34']);
        $this->assertSame('/usr/local/bin:/usr/bin:/bin', $env['PATH']);
    }

    public function test_the_real_process_inherits_no_application_secret(): void
    {
        if (!is_executable('/usr/bin/env')) {
            $this->markTestSkipped('لا /usr/bin/env في هذه البيئة.');
        }
        $_ENV['WA_TEST_SECRET_E34'] = 'not-for-ffmpeg';
        try {
            $process = new Process(['/usr/bin/env'], null, MediaConverter::processEnv());
            $process->run();
        } finally {
            unset($_ENV['WA_TEST_SECRET_E34']);
        }

        $this->assertSame('PATH=/usr/local/bin:/usr/bin:/bin', trim($process->getOutput()));
    }

    public function test_a_declared_duration_over_the_limit_is_refused_before_encoding(): void
    {
        $this->fakeConverter(inputSeconds: 130.0);
        $in = $this->input();

        try {
            MediaConverter::toVoice($in);
            $this->fail('توقعتُ VoiceTooLongException');
        } catch (VoiceTooLongException $e) {
            $this->assertSame(['VOICE_TOO_LONG', 422], [$e->reason, $e->status]);
            $this->assertStringContainsString('2:00', $e->getMessage());
        }
        $this->assertSame([], $this->encodeCalls());
        $this->assertSame([], $this->leftovers());
    }

    public function test_a_recording_without_a_declared_duration_is_measured_after_encoding(): void
    {
        $this->fakeConverter(inputSeconds: 'N/A', outputSeconds: 126.0);

        $this->expectException(VoiceTooLongException::class);
        try {
            MediaConverter::toVoice($this->input());
        } finally {
            $this->assertSame([], $this->leftovers());
        }
    }

    public function test_an_output_over_512kb_is_encoded_once_more_at_the_lower_bitrate(): void
    {
        $this->fakeConverter(bytesByBitrate: ['32k' => 600 * 1024, '24k' => 400 * 1024]);

        $out = MediaConverter::toVoice($this->input());

        $this->assertSame(['32k', '24k'], array_map(fn ($cmd) => $cmd[array_search('-b:a', $cmd, true) + 1], $this->encodeCalls()));
        $this->assertLessThanOrEqual(512 * 1024, $out['bytes']);
        @unlink($out['path']);
    }

    public function test_still_too_large_after_the_second_encoding_is_refused_and_nothing_is_left(): void
    {
        $this->fakeConverter(bytesByBitrate: ['32k' => 700 * 1024, '24k' => 600 * 1024]);

        try {
            MediaConverter::toVoice($this->input());
            $this->fail('توقعتُ VoiceTooLongException');
        } catch (VoiceTooLongException $e) {
            $this->assertSame('VOICE_TOO_LARGE', $e->reason);
        }
        $this->assertCount(2, $this->encodeCalls());
        $this->assertSame([], $this->leftovers());
    }

    public function test_a_second_conversion_while_one_runs_answers_busy_without_running_anything(): void
    {
        $this->fakeConverter();
        $held = Cache::lock(MediaConverter::LOCK, 75);
        $this->assertTrue($held->get());
        $in = $this->input();
        $started = microtime(true);

        try {
            MediaConverter::toVoice($in);
            $this->fail('توقعتُ ConverterBusyException');
        } catch (ConverterBusyException $e) {
            $this->assertSame(['CONVERTER_BUSY', 503, 'المحوّل مشغول، أعد المحاولة بعد ثوانٍ.'], [$e->reason, $e->status, $e->getMessage()]);
        } finally {
            $held->release();
        }

        $this->assertLessThan(3, microtime(true) - $started);
        $this->assertSame([], $this->converterCalls);
        $this->assertFileDoesNotExist($in);
        $this->assertSame(1, MediaConverter::stats()['busy']);
    }

    public function test_a_timeout_removes_every_temporary_file(): void
    {
        $this->fakeConverter(encodeFailure: ['exit' => -1, 'err' => 'timeout', 'timed_out' => true]);

        try {
            MediaConverter::toVoice($this->input());
            $this->fail('توقعتُ VoiceConversionException');
        } catch (VoiceConversionException $e) {
            $this->assertSame(['VOICE_CONVERT_TIMEOUT', 422], [$e->reason, $e->status]);
        }
        $this->assertSame([], $this->leftovers());
        // القفل حُرّر: التحويل التالي لا يرد «مشغول»
        $this->assertTrue(Cache::lock(MediaConverter::LOCK, 5)->get());
    }

    public function test_a_failed_encoding_logs_the_exit_code_and_a_short_stderr_without_paths(): void
    {
        $this->captureLogs();
        $this->fakeConverter(encodeFailure: ['exit' => 1, 'err' => MediaConverter::tmpDir().'/x.in: Invalid data found '.str_repeat('z', 900)]);

        try {
            MediaConverter::toVoice($this->input());
            $this->fail('توقعتُ VoiceConversionException');
        } catch (VoiceConversionException $e) {
            $this->assertSame('تعذّر تجهيز الرسالة الصوتية — سجّلها من جديد.', $e->getMessage());
        }

        $line = collect($this->logged)->firstWhere('message', 'wa.voice.failed');
        $this->assertNotNull($line);
        $this->assertSame(['VOICE_CONVERT_FAILED', 1], [$line['context']['reason'], $line['context']['exit']]);
        $this->assertSame(500, mb_strlen($line['context']['stderr']));
        $this->assertStringStartsWith('<tmp>/x.in', $line['context']['stderr']);
        $this->assertSame(1, MediaConverter::stats()['failed']);
    }

    public function test_doctor_version_and_daily_counters(): void
    {
        $this->fakeConverter();
        $out = MediaConverter::toVoice($this->input());
        @unlink($out['path']);

        $this->assertSame('ffmpeg version 6.1-test Copyright (c) the FFmpeg developers', MediaConverter::version());
        $stats = MediaConverter::stats();
        $this->assertSame([1, 0, 0], [$stats['ok'], $stats['busy'], $stats['failed']]);
        $this->assertIsInt($stats['avg_ms']);
    }

    // ==================== ffmpeg الفعلي (حيث يوجد، وإلا يُتخطى) ====================

    private function realFfmpeg(): void
    {
        if ($path = getenv('FFMPEG_TEST_PATH')) {
            config(['whatsapp.voice.ffmpeg_path' => $path, 'whatsapp.voice.ffprobe_path' => dirname($path).'/ffprobe']);
        }
        MediaConverter::fake(null);
        if (!MediaConverter::available()) {
            $this->markTestSkipped('لا ffmpeg في هذه البيئة.');
        }
    }

    /** عيّنة نغمة يولّدها ffmpeg نفسه (لا ملفات ثنائية في المستودع) */
    private function sine(float $seconds, string $format, string $codec, string $ext, int $channels = 2): string
    {
        $path = MediaConverter::tmpPath($ext);
        $p = new Process([config('whatsapp.voice.ffmpeg_path'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi',
            '-i', "sine=frequency=440:duration={$seconds}", '-ac', (string) $channels, '-c:a', $codec, '-b:a', '16k', '-f', $format, '-y', $path]);
        $p->run();
        if (!$p->isSuccessful()) {
            $this->markTestSkipped("ffmpeg لا يبني عيّنة {$format}/{$codec}.");
        }

        return $path;
    }

    #[Group('ffmpeg')]
    public function test_real_ffmpeg_turns_stereo_webm_and_mp4_aac_into_valid_voice_notes(): void
    {
        $this->realFfmpeg();

        foreach ([['webm', 'libopus', 'webm'], ['mp4', 'aac', 'm4a'], ['ogg', 'libopus', 'ogg']] as [$format, $codec, $ext]) {
            $out = MediaConverter::toVoice($this->sine(3, $format, $codec, $ext));
            try {
                $this->assertNull(VoiceNoteValidator::check($out['path']), $format);
                $this->assertLessThanOrEqual(512 * 1024, $out['bytes']);
                $this->assertEqualsWithDelta(3.0, $out['seconds'], 0.2, $format);
            } finally {
                @unlink($out['path']);
            }
        }
        $this->assertSame([], $this->leftovers());
    }

    #[Group('ffmpeg')]
    public function test_real_ffmpeg_refuses_130_seconds(): void
    {
        $this->realFfmpeg();

        $this->expectException(VoiceTooLongException::class);
        try {
            MediaConverter::toVoice($this->sine(130, 'webm', 'libopus', 'webm', 1));
        } finally {
            $this->assertSame([], $this->leftovers());
        }
    }

    #[Group('ffmpeg')]
    public function test_real_ffmpeg_never_runs_a_shell_for_the_file_name(): void
    {
        $this->realFfmpeg();
        // العملية تعمل في المجلد المؤقت نفسه: لو نُفّذ الاسم في shell لظهر pwned هنا
        $marker = MediaConverter::tmpDir().'/pwned';
        $sample = $this->sine(2, 'ogg', 'libopus', 'ogg');
        $weird = MediaConverter::tmpDir().'/a; touch pwned $(touch pwned2) `touch pwned3`.ogg';
        rename($sample, $weird);

        $out = MediaConverter::toVoice($weird);
        @unlink($out['path']);

        $this->assertFileDoesNotExist($marker);
        $this->assertFileDoesNotExist($marker.'2');
        $this->assertFileDoesNotExist($marker.'3');
        $this->assertSame([], $this->leftovers());
    }
}
