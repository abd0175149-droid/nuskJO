<?php

namespace Tests\Concerns;

use App\Services\WhatsApp\MediaConverter;

/**
 * رسائل الموظف الصوتية في الاختبار (E34): صفحات Ogg/Opus تُبنى بايتاتها هنا (لا ملفات ثنائية في المستودع)، ومحوّل مزيّف
 * يجيب ffprobe بمدة ويكتب ffmpeg ناتجه في آخر وسيط، ويسجّل كل عملية طُلبت. لا يعتمد على وجود ffmpeg.
 */
trait FakesVoiceConverter
{
    /** @var list<list<string>> العمليات المطلوبة بوسائطها */
    protected array $converterCalls = [];

    protected function tearDownVoiceConverter(): void
    {
        MediaConverter::fake(null);
        $this->converterCalls = [];
        foreach (glob(MediaConverter::tmpDir().'/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * @param  float|string|null  $inputSeconds  ما يقوله ffprobe عن التسجيل الخام ('N/A' لتسجيل Chrome بلا مدة)
     * @param  array<string, int>  $bytesByBitrate  حجم الناتج لكل معدل (للحجم فوق 512KB ثم إعادة الترميز)
     * @param  array{exit?: int, err?: string, timed_out?: bool}|null  $encodeFailure  فشل ffmpeg مفتعل
     */
    protected function fakeConverter(float|string|null $inputSeconds = 3.0, ?float $outputSeconds = null, int $channels = 1,
        array $bytesByBitrate = [], ?array $encodeFailure = null): void
    {
        $this->converterCalls = [];
        MediaConverter::fake(function (array $cmd, float $timeout) use ($inputSeconds, $outputSeconds, $channels, $bytesByBitrate, $encodeFailure) {
            $this->converterCalls[] = $cmd;
            $target = (string) end($cmd);
            if ($cmd[0] === config('whatsapp.voice.ffprobe_path')) {
                $isOutput = str_ends_with($target, '.ogg');
                $value = $isOutput ? ($outputSeconds ?? (is_numeric($inputSeconds) ? (float) $inputSeconds : 3.0)) : $inputSeconds;

                return ['exit' => 0, 'out' => $value === null ? 'N/A' : (string) $value];
            }
            if (in_array('-version', $cmd, true)) {
                return ['exit' => 0, 'out' => "ffmpeg version 6.1-test Copyright (c) the FFmpeg developers\nbuilt with gcc"];
            }
            if ($encodeFailure !== null) {
                return $encodeFailure + ['exit' => 1, 'err' => 'Invalid data found when processing input'];
            }
            $bitrate = $cmd[array_search('-b:a', $cmd, true) + 1] ?? '32k';
            file_put_contents($target, $this->oggOpusBytes($channels, $bytesByBitrate[$bitrate] ?? 0));

            return ['exit' => 0];
        });
    }

    /** عمليات ffmpeg (التحويل) وحدها */
    protected function encodeCalls(): array
    {
        return array_values(array_filter($this->converterCalls, fn (array $cmd) => $cmd[0] === config('whatsapp.voice.ffmpeg_path') && !in_array('-version', $cmd, true)));
    }

    /** صفحة OpusHead ثم OpusTags، وصفحة بيانات حشو اختيارية ترفع الحجم إلى $padTo */
    protected function oggOpusBytes(int $channels = 1, int $padTo = 0): string
    {
        $head = 'OpusHead'.chr(1).chr($channels).pack('v', 312).pack('V', 48000).pack('v', 0).chr(0);
        $tags = 'OpusTags'.pack('V', 4).'test'.pack('V', 0);
        $bytes = $this->oggPage($head, 2, 0).$this->oggPage($tags, 0, 1);
        $seq = 2;
        while (strlen($bytes) < $padTo) {
            $bytes .= $this->oggPage(str_repeat("\x55", min(255, max(1, $padTo - strlen($bytes) - 28))), 0, $seq++);
        }

        return $bytes;
    }

    /** صفحة Ogg بحزمة واحدة (حتى 255 بايتاً) */
    protected function oggPage(string $packet, int $headerType, int $sequence): string
    {
        return 'OggS'."\x00".chr($headerType).pack('P', 0).pack('V', 7341).pack('V', $sequence)."\x00\x00\x00\x00".chr(1).chr(strlen($packet)).$packet;
    }

    /** صفحة Vorbis (ogg بلا OPUS) */
    protected function oggVorbisBytes(): string
    {
        return $this->oggPage("\x01vorbis".pack('V', 0).chr(1).pack('V', 44100).str_repeat("\0", 13)."\x01", 2, 0);
    }
}
