<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\Outbound\Mp4Probe;
use Tests\Concerns\BuildsMediaSamples;
use Tests\TestCase;

/** فحص ترميز الفيديو بلا ffmpeg (E32.4): H.264 مع AAC يُقبل، وHEVC والمسارات الصوتية المتعددة تُرفض، والناقص خطأ مقروء */
class Mp4ProbeTest extends TestCase
{
    use BuildsMediaSamples;

    protected function tearDown(): void
    {
        $this->tearDownMediaSamples();
        parent::tearDown();
    }

    public function test_h264_with_aac_is_accepted_and_faststart_is_reported(): void
    {
        $result = Mp4Probe::inspect($this->sampleFile($this->mp4Bytes('avc1', ['mp4a']), 'mp4'));

        $this->assertTrue($result['ok']);
        $this->assertSame(['avc1', ['mp4a'], true], [$result['video'], $result['audio'], $result['faststart']]);
    }

    public function test_index_at_the_end_is_accepted_with_a_faststart_hint(): void
    {
        $result = Mp4Probe::inspect($this->sampleFile($this->mp4Bytes('avc3', [], moovFirst: false), 'mp4'));

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['faststart']);
        $this->assertSame([], $result['audio']);
    }

    public function test_hevc_is_refused_with_an_arabic_reason(): void
    {
        $result = Mp4Probe::inspect($this->sampleFile($this->mp4Bytes('hvc1'), 'mp4'));

        $this->assertFalse($result['ok']);
        $this->assertSame('VIDEO_HEVC', $result['code']);
        $this->assertStringContainsString('H.264', $result['error']);
        $this->assertSame('hvc1', $result['video']);
    }

    public function test_other_codecs_and_two_audio_tracks_are_refused(): void
    {
        $this->assertSame('VIDEO_CODEC', Mp4Probe::inspect($this->sampleFile($this->mp4Bytes('vp09'), 'mp4'))['code']);
        $this->assertSame('VIDEO_AUDIO_TRACKS', Mp4Probe::inspect($this->sampleFile($this->mp4Bytes('avc1', ['mp4a', 'mp4a']), 'mp4'))['code']);
        $this->assertSame('VIDEO_AUDIO_CODEC', Mp4Probe::inspect($this->sampleFile($this->mp4Bytes('avc1', ['samr']), '3gp'))['code']);
        $this->assertSame('VIDEO_NO_TRACK', Mp4Probe::inspect($this->sampleFile($this->mp4Bytes('', ['mp4a']), 'mp4'))['code']);
    }

    public function test_a_truncated_or_foreign_file_gives_a_readable_error_not_an_exception(): void
    {
        $whole = $this->mp4Bytes('avc1');
        $cut = Mp4Probe::inspect($this->sampleFile(substr($whole, 0, (int) (strlen($whole) * 0.9)), 'mp4'));
        $noIndex = Mp4Probe::inspect($this->sampleFile(substr($this->mp4Bytes('avc1', [], moovFirst: false), 0, 2100), 'mp4'));
        $garbage = Mp4Probe::inspect($this->sampleFile(str_repeat("\xAB", 512), 'mp4'));

        foreach ([$cut, $noIndex, $garbage] as $result) {
            $this->assertFalse($result['ok']);
            $this->assertSame('VIDEO_TRUNCATED', $result['code']);
            $this->assertStringContainsString('تعذّر قراءة الفيديو', $result['error']);
        }
        $this->assertSame('VIDEO_UNREADABLE', Mp4Probe::inspect('/nonexistent/clip.mp4')['code']);
    }

    public function test_a_64_bit_box_size_is_read(): void
    {
        $bytes = $this->mp4Bytes('avc1', ['mp4a'], mdatBytes: 16);
        // mdat الأخير بحجم 64-bit (size=1 ثم largesize)
        $mdatAt = strrpos($bytes, 'mdat') - 4;
        $payload = substr($bytes, $mdatAt + 8);
        $bytes = substr($bytes, 0, $mdatAt).pack('N', 1).'mdat'.pack('NN', 0, 16 + strlen($payload)).$payload;

        $this->assertTrue(Mp4Probe::inspect($this->sampleFile($bytes, 'mp4'))['ok']);
    }
}
