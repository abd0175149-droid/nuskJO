<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * الصفحة الأولى من ملف PDF صورةَ PNG (pdftoppm من poppler-utils في الصورة).
 * لورقة الشركة الرسمية: يرفع المالك ملف قالب الطباعة نفسه، فتصير صفحته الأولى خلفية فاتورة PDF وبطاقات العروض
 * كما لو رُفعت صورة. الضلع الأطول 2339 نقطة (A4 بدقة 200)، فلا يكبر الناتج مهما كان مقاس الصفحة، والمهلة 30 ثانية.
 */
final class PdfRasterizer
{
    public const LONG_SIDE = 2339;

    public const TIMEOUT_SECONDS = 30;

    /** يعيد المسار النسبي لـ PNG على القرص، أو null إن تعذّرت القراءة */
    public static function firstPage(string $pdfAbsolutePath, string $disk, string $dir): ?string
    {
        $base = trim($dir, '/').'/'.Str::random(40);
        $target = Storage::disk($disk)->path($base);
        Storage::disk($disk)->makeDirectory(trim($dir, '/'));

        $result = Process::timeout(self::TIMEOUT_SECONDS)->run([
            'pdftoppm', '-q', '-png', '-singlefile', '-f', '1', '-l', '1',
            '-scale-to', (string) self::LONG_SIDE, $pdfAbsolutePath, $target,
        ]);

        $png = $base.'.png';
        if (!$result->successful() || !Storage::disk($disk)->exists($png) || Storage::disk($disk)->size($png) === 0) {
            Storage::disk($disk)->delete($png);
            // رمز الخروج وحده: لا مسارات ولا محتوى الملف في السجل
            Log::warning('pdf.rasterize_failed', ['exit' => $result->exitCode()]);

            return null;
        }

        return $png;
    }
}
