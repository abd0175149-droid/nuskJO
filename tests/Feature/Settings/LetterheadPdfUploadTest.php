<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Services\PdfRasterizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ورقة الشركة الرسمية تقبل PDF (ملف قالب الطباعة نفسه): تُحفظ صفحته الأولى PNG في brand/ ولا يُحفظ الـ PDF،
 * فتستعملها فاتورة PDF وبطاقات العروض كأي صورة. فشل القراءة رسالة واضحة والورقة السابقة باقية.
 */
class LetterheadPdfUploadTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('letterhead.pdf', self::PDF);
    }

    /** pdftoppm مزيّف يكتب PNG حيث طُلب، كالأداة الحقيقية */
    private function fakeRasterizer(bool $ok = true): void
    {
        Process::fake(function (PendingProcess $process) use ($ok) {
            if ($ok) {
                file_put_contents(end($process->command).'.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='));
            }

            return Process::result(exitCode: $ok ? 0 : 1);
        });
    }

    public function test_a_pdf_letterhead_becomes_its_first_page_as_png(): void
    {
        $this->fakeRasterizer();

        $this->actingAs(User::factory()->admin()->create())
            ->post('/settings/brand-asset', ['type' => 'letterhead', 'asset' => $this->pdf()])
            ->assertSessionHasNoErrors();

        $path = Setting::get('card_letterhead_path');
        $this->assertMatchesRegularExpression('#^brand/[A-Za-z0-9]{40}\.png$#', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame([], array_values(array_filter(Storage::disk('public')->allFiles(), fn ($f) => str_ends_with($f, '.pdf'))), 'الـ PDF لا يُحفظ على القرص العام');

        Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'pdftoppm'
            && in_array('-singlefile', $p->command, true)
            && in_array((string) PdfRasterizer::LONG_SIDE, $p->command, true)
            && $p->timeout === PdfRasterizer::TIMEOUT_SECONDS);
    }

    public function test_an_unreadable_pdf_keeps_the_previous_letterhead(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/settings/brand-asset', ['type' => 'letterhead', 'asset' => UploadedFile::fake()->image('paper.png', 800, 1131)]);
        $previous = Setting::get('card_letterhead_path');

        $this->fakeRasterizer(ok: false);
        $this->actingAs($admin)->post('/settings/brand-asset', ['type' => 'letterhead', 'asset' => $this->pdf()])
            ->assertSessionHasErrors(['asset' => 'تعذّرت قراءة ملف PDF. جرّب ملفاً آخر أو صورة PNG أو JPG.']);

        $this->assertSame($previous, Setting::get('card_letterhead_path'));
        Storage::disk('public')->assertExists($previous);
    }

    public function test_only_the_letterhead_accepts_pdf(): void
    {
        $this->fakeRasterizer();
        $admin = User::factory()->admin()->create();

        foreach (['logo', 'hero'] as $type) {
            $this->actingAs($admin)->post('/settings/brand-asset', ['type' => $type, 'asset' => $this->pdf()])->assertSessionHasErrors('asset');
        }
        Process::assertNothingRan();
    }
}
