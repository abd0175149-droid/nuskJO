<?php

namespace Tests\Concerns;

use Illuminate\Support\Str;

/**
 * عيّنات ملفات صغيرة تُبنى في الاختبار (E32، كما في E14: لا ملفات ثنائية في tests/Fixtures): JPEG وPNG بترويسات حقيقية
 * يقرؤها finfo وgetimagesize، وصناديق MP4 (ISO-BMFF) بترميز نختاره، وPDF بقواميس ومجاري. لا محتوى ولا بيانات شخصية.
 */
trait BuildsMediaSamples
{
    /** @var list<string> */
    private array $sampleFiles = [];

    /** ملف مؤقت بالبايتات المعطاة، يُحذف في tearDown */
    protected function sampleFile(string $bytes, string $ext = 'bin'): string
    {
        $dir = storage_path('framework/testing/e32-samples');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir.'/'.Str::uuid7().'.'.$ext;
        file_put_contents($path, $bytes);

        return $this->sampleFiles[] = $path;
    }

    protected function tearDownMediaSamples(): void
    {
        foreach ($this->sampleFiles as $path) {
            @unlink($path);
        }
        $this->sampleFiles = [];
    }

    /** SOI ثم SOF0 بعدد مكوّنات (3 = RGB/YCbCr، 4 = CMYK) ثم EOI، وحشو اختياري يرفع الحجم */
    protected function jpegBytes(int $components = 3, int $padTo = 0): string
    {
        $sof = "\xFF\xC0".pack('n', 8 + 3 * $components)."\x08".pack('n', 10).pack('n', 10).chr($components);
        for ($i = 1; $i <= $components; $i++) {
            $sof .= chr($i)."\x11\x00";
        }
        $com = $padTo > 0 ? str_repeat("\xFF\xFE".pack('n', 65000).str_repeat("\x20", 64998), intdiv($padTo, 65002) + 1) : '';

        return "\xFF\xD8".$sof.$com."\xFF\xD9";
    }

    /** توقيع PNG ثم IHDR بعمق الألوان المطلوب، وكتلة نص اختيارية ترفع الحجم */
    protected function pngBytes(int $bits = 8, int $padTo = 0): string
    {
        $chunk = fn (string $t, string $d) => pack('N', strlen($d)).$t.$d.pack('N', crc32($t.$d));
        $pad = $padTo > 0 ? $chunk('tEXt', 'pad'."\0".str_repeat('x', $padTo)) : '';

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NN', 4, 4).chr($bits)."\x06\x00\x00\x00").$pad.$chunk('IEND', '');
    }

    /**
     * MP4 مصغّر: ftyp ثم moov (مسار فيديو بالترميز المعطى ومسارات صوت) ثم mdat، أو moov بعد mdat.
     *
     * @param  list<string>  $audio
     */
    protected function mp4Bytes(string $video = 'avc1', array $audio = ['mp4a'], bool $moovFirst = true, string $brand = 'isom', int $mdatBytes = 2048): string
    {
        $box = fn (string $type, string $payload) => pack('N', 8 + strlen($payload)).$type.$payload;
        $full = fn (string $type, string $payload) => $box($type, "\0\0\0\0".$payload);
        $entry = fn (string $fmt) => $box($fmt, str_repeat("\0", 6).pack('n', 1).str_repeat("\0", 70));
        $trak = fn (string $handler, string $fmt) => $box('trak', $box('mdia',
            $full('hdlr', pack('N', 0).$handler.str_repeat("\0", 12)."\0").$box('minf', $box('stbl', $full('stsd', pack('N', 1).$entry($fmt))))));

        $tracks = $video !== '' ? $trak('vide', $video) : '';
        foreach ($audio as $fmt) {
            $tracks .= $trak('soun', $fmt);
        }
        $ftyp = $box('ftyp', $brand.pack('N', 512).$brand.'isomavc1');
        $moov = $box('moov', $full('mvhd', str_repeat("\0", 96)).$tracks);
        $mdat = $box('mdat', str_repeat("\x11", $mdatBytes));

        return $moovFirst ? $ftyp.$moov.$mdat : $ftyp.$mdat.$moov;
    }

    /** PDF صغير: كتالوج وصفحة، مع قاموس إضافي اختياري ومجرى ثنائي اختياري */
    protected function pdfBytes(string $extraDict = '', string $streamBody = ''): string
    {
        $objects = [
            '1 0 obj << /Type /Catalog /Pages 2 0 R '.$extraDict.' >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >> endobj',
        ];
        if ($streamBody !== '') {
            $objects[] = '4 0 obj << /Length '.strlen($streamBody)." >>\nstream\n".$streamBody."\nendstream\nendobj";
        }

        return "%PDF-1.7\n".implode("\n", $objects)."\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /** PDF يخفي /JS داخل مجرى كائنات مضغوط (ObjStm) */
    protected function pdfWithCompressedScript(): string
    {
        $inner = '5 0 << /S /JavaScript /JS (app.alert(1)) >>';
        $packed = gzcompress($inner);

        return "%PDF-1.7\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            ."2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n"
            .'6 0 obj << /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length '.strlen($packed)." >>\nstream\n".$packed."\nendstream\nendobj\n"
            ."trailer << /Root 1 0 R >>\n%%EOF\n";
    }
}
