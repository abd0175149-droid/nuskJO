<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\Outbound\OutboundMediaPolicy;
use Tests\Concerns\BuildsMediaSamples;
use Tests\TestCase;

/**
 * فحص الملف الصادر (E32.4): النوع من المحتوى بـ finfo لا من الاسم، وحد لكل نوع، والصور 8-bit RGB، والفيديو H.264،
 * وPDF بلا محتوى نشط، وOffice بقرار المالك وبلا ماكرو.
 */
class OutboundMediaPolicyTest extends TestCase
{
    use BuildsMediaSamples;

    protected function tearDown(): void
    {
        $this->tearDownMediaSamples();
        parent::tearDown();
    }

    private function inspect(string $bytes, string $name): array
    {
        return OutboundMediaPolicy::inspect($this->sampleFile($bytes, pathinfo($name, PATHINFO_EXTENSION) ?: 'bin'), $name);
    }

    public function test_a_plain_pdf_passes_with_its_fingerprint(): void
    {
        $bytes = $this->pdfBytes();
        $result = $this->inspect($bytes, 'تأشيرة العميل.pdf');

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame(['document', 'application/pdf', 'pdf', 'تأشيرة العميل.pdf', strlen($bytes), hash('sha256', $bytes)],
            [$result['type'], $result['mime'], $result['ext'], $result['name'], $result['size'], $result['sha256']]);
    }

    public function test_the_type_comes_from_the_content_not_the_name(): void
    {
        $exe = $this->inspect("MZ\x90\x00".str_repeat("\0", 60).'PE'."\0\0", 'invoice.pdf');
        $this->assertFalse($exe['ok']);
        $this->assertSame('MEDIA_TYPE', $exe['code']);

        // صورة JPEG باسم .png: تُقبل باسم مصحَّح
        $jpeg = $this->inspect($this->jpegBytes(), 'passport-scan.png');
        $this->assertTrue($jpeg['ok']);
        $this->assertSame(['image', 'image/jpeg', 'jpg', 'passport-scan.jpg'], [$jpeg['type'], $jpeg['mime'], $jpeg['ext'], $jpeg['name']]);
    }

    public function test_images_over_5mb_cmyk_and_16_bit_are_refused(): void
    {
        $big = $this->inspect($this->pngBytes(8, 5 * 1024 * 1024 + 10), 'big.png');
        $this->assertSame('MEDIA_TOO_LARGE', $big['code']);
        $this->assertStringContainsString('صغّرها أو حوّلها إلى PDF', $big['error']);

        $cmyk = $this->inspect($this->jpegBytes(4), 'print.jpg');
        $this->assertSame('IMAGE_CMYK', $cmyk['code']);
        $this->assertStringContainsString('حوّل الصورة إلى RGB', $cmyk['error']);

        $this->assertSame('IMAGE_16BIT', $this->inspect($this->pngBytes(16), 'deep.png')['code']);
        $this->assertTrue($this->inspect($this->pngBytes(8), 'ok.png')['ok']);
    }

    public function test_heic_and_webp_are_refused_on_the_server_with_a_conversion_hint(): void
    {
        $box = fn (string $type, string $payload) => pack('N', 8 + strlen($payload)).$type.$payload;
        $heic = $this->inspect($box('ftyp', 'heic'.pack('N', 0).'mif1heic').$box('meta', str_repeat("\0", 20)), 'IMG_0001.HEIC');

        $this->assertSame('MEDIA_CONVERT', $heic['code']);
        $this->assertStringContainsString('حوّل الصورة إلى JPG', $heic['error']);
        $this->assertSame('MEDIA_CONVERT', $this->inspect('RIFF'.pack('V', 30).'WEBPVP8 '.pack('V', 18).str_repeat("\0", 18), 'a.webp')['code']);
    }

    public function test_active_pdf_content_is_refused_wherever_it_hides(): void
    {
        $cases = [
            'open action script' => $this->pdfBytes('/OpenAction << /S /JavaScript /JS (app.alert(1)) >>'),
            'launch' => $this->pdfBytes('/AA << /O << /S /Launch /F (cmd) >> >>'),
            'embedded file' => $this->pdfBytes('/Names << /EmbeddedFiles 9 0 R >>'),
            'hex escaped name' => $this->pdfBytes('/OpenAction << /S /J#61vaScript >>'),
            'compressed object stream' => $this->pdfWithCompressedScript(),
        ];
        foreach ($cases as $label => $bytes) {
            $result = $this->inspect($bytes, 'doc.pdf');
            $this->assertSame('PDF_ACTIVE', $result['code'], $label);
            $this->assertStringContainsString('احفظه PDF عادياً من جديد', $result['error'], $label);
        }
    }

    public function test_binary_stream_bytes_are_not_mistaken_for_a_script(): void
    {
        // مجرى صورة يحوي البايتات «/JS » مصادفة: خارج القواميس فلا يُعد محتوى نشطاً
        $result = $this->inspect($this->pdfBytes('', "\x00\x01/JS \x02/JavaScript\x03"), 'scan.pdf');

        $this->assertTrue($result['ok'], (string) $result['error']);
    }

    public function test_documents_follow_the_owner_limit(): void
    {
        config(['whatsapp.outbound.max_document_mb' => 1]);
        $pdf = $this->pdfBytes('', str_repeat('A', 1024 * 1024 + 10));

        $result = $this->inspect($pdf, 'big.pdf');
        $this->assertSame('MEDIA_TOO_LARGE', $result['code']);
        $this->assertStringContainsString('1MB', $result['error']);
        // أكبر حد بين المسموح: الفيديو 16MB، وبلا فيديو الصورة 5MB
        $this->assertSame(16 * 1024, OutboundMediaPolicy::maxUploadKb());
        config(['whatsapp.outbound.video' => false]);
        $this->assertSame(5 * 1024, OutboundMediaPolicy::maxUploadKb());
    }

    public function test_video_must_be_h264(): void
    {
        $ok = $this->inspect($this->mp4Bytes('avc1', ['mp4a']), 'clip.mp4');
        $this->assertSame(['video', 'video/mp4', null], [$ok['type'], $ok['mime'], $ok['warning']]);

        $late = $this->inspect($this->mp4Bytes('avc1', [], moovFirst: false), 'clip.mp4');
        $this->assertTrue($late['ok']);
        $this->assertStringContainsString('faststart', (string) $late['warning']);

        $this->assertSame('VIDEO_HEVC', $this->inspect($this->mp4Bytes('hvc1'), 'iphone.mov')['code']);
        $this->assertTrue($this->inspect($this->mp4Bytes('avc1', ['mp4a'], brand: '3gp4'), 'old.3gp')['ok']);
    }

    public function test_video_can_be_switched_off_without_a_deploy(): void
    {
        config(['whatsapp.outbound.video' => false]);

        $this->assertSame('MEDIA_TYPE', $this->inspect($this->mp4Bytes('avc1'), 'clip.mp4')['code']);
    }

    public function test_office_documents_need_the_owner_switch_and_never_carry_macros(): void
    {
        $docx = $this->office('word/document.xml');
        $this->assertSame('MEDIA_TYPE', $this->inspect($docx, 'letter.docx')['code']);

        config(['whatsapp.outbound.office_docs' => true]);
        $ok = $this->inspect($docx, 'letter.docx');
        $this->assertTrue($ok['ok']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $ok['mime']);
        $this->assertSame('MEDIA_TYPE', $this->inspect($this->office('word/document.xml', macro: true), 'letter.docm')['code']);
        $this->assertSame('xlsx', $this->inspect($this->office('xl/workbook.xml'), 'sheet.zip')['ext']);
        $this->assertTrue($this->inspect("بيانات الرحلة\n", 'notes.txt')['ok']);
    }

    public function test_the_client_policy_lists_types_limits_and_document_kinds(): void
    {
        config(['features.env.staff_file_send' => 'false']);
        $policy = OutboundMediaPolicy::forClient();

        $this->assertFalse($policy['enabled']);
        $this->assertSame(['image', 'image', 'video', 'video', 'document'], array_column($policy['types'], 'kind'));
        $this->assertSame(16 * 1024 * 1024, $policy['types'][4]['max']);
        $this->assertStringContainsString('.heic', $policy['accept']);
        $this->assertArrayHasKey('visa', $policy['doc_kinds']);
        $this->assertSame(16 * 1024, OutboundMediaPolicy::maxUploadKb());
    }

    private function office(string $main, bool $macro = false): string
    {
        $path = $this->sampleFile('', 'zip');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .($macro ? '<Default Extension="bin" ContentType="application/vnd.ms-word.document.macroEnabled.12"/>' : '').'</Types>');
        $zip->addFromString($main, '<x/>');
        if ($macro) {
            $zip->addFromString('word/vbaProject.bin', 'VBA');
        }
        $zip->close();

        return (string) file_get_contents($path);
    }
}
