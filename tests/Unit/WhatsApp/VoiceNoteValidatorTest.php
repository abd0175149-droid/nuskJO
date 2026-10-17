<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\VoiceNoteValidator;
use Tests\Concerns\BuildsMediaSamples;
use Tests\Concerns\FakesVoiceConverter;
use Tests\TestCase;

/**
 * شرط ميتا للرسالة الصوتية قبل الرفع (E34.4): Ogg ثم OpusHead بقناة واحدة، وfinfo يقول ogg، و512KB أو أقل.
 * العيّنات بايتات تُبنى في الاختبار.
 */
class VoiceNoteValidatorTest extends TestCase
{
    use BuildsMediaSamples;
    use FakesVoiceConverter;

    protected function tearDown(): void
    {
        $this->tearDownMediaSamples();
        parent::tearDown();
    }

    public function test_a_mono_ogg_opus_file_is_accepted(): void
    {
        $this->assertNull(VoiceNoteValidator::check($this->sampleFile($this->oggOpusBytes(1), 'ogg')));
        $this->assertNull(VoiceNoteValidator::check($this->sampleFile($this->oggOpusBytes(1, 500 * 1024), 'ogg')));
    }

    public function test_stereo_is_refused(): void
    {
        $this->assertSame('الرسالة الصوتية يجب أن تكون بقناة واحدة (mono).', VoiceNoteValidator::check($this->sampleFile($this->oggOpusBytes(2), 'ogg')));
    }

    public function test_an_mp3_is_refused(): void
    {
        $mp3 = 'ID3'."\x04\x00\x00\x00\x00\x00\x00"."\xFF\xFB\x90\x64".str_repeat("\0", 400);

        $this->assertSame('الملف ليس بصيغة Ogg.', VoiceNoteValidator::check($this->sampleFile($mp3, 'mp3')));
    }

    public function test_a_file_over_512kb_is_refused(): void
    {
        $this->assertStringContainsString('512KB', (string) VoiceNoteValidator::check($this->sampleFile($this->oggOpusBytes(1, 513 * 1024), 'ogg')));
    }

    public function test_random_bytes_named_ogg_and_ogg_vorbis_are_refused(): void
    {
        $this->assertSame('الملف ليس بصيغة Ogg.', VoiceNoteValidator::check($this->sampleFile(random_bytes(2048), 'ogg')));
        $this->assertSame('الترميز ليس OPUS.', VoiceNoteValidator::check($this->sampleFile($this->oggVorbisBytes(), 'ogg')));
        $this->assertSame('الترميز ليس OPUS.', VoiceNoteValidator::check($this->sampleFile('OggS'.str_repeat("\0", 60), 'ogg')));
        $this->assertSame('الملف الصوتي فارغ.', VoiceNoteValidator::check($this->sampleFile('', 'ogg')));
    }

    public function test_the_raw_recording_allow_list_is_narrow(): void
    {
        foreach (['audio/webm', 'video/webm', 'audio/ogg', 'application/ogg', 'audio/mp4', 'video/mp4', 'audio/x-m4a'] as $mime) {
            $this->assertTrue(VoiceNoteValidator::rawMimeAllowed($mime), $mime);
        }
        foreach (['audio/mpeg', 'audio/wav', 'application/pdf', 'text/plain', 'application/octet-stream', null] as $mime) {
            $this->assertFalse(VoiceNoteValidator::rawMimeAllowed($mime), (string) $mime);
        }
    }
}
