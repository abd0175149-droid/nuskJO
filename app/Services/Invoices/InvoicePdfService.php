<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\Setting;
use App\Services\CompanyInfo;
use App\Services\OfferCardService;
use App\Services\Rendering\HeadlessChromium;
use App\Services\WhatsApp\Media\MediaArchiver;
use App\Services\WhatsApp\Outbound\OutboundMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * فاتورة PDF للعميل (E32.8): قالب invoices.pdf المستقل ببيانات InvoicePdfData، مطبوعاً بكروميوم بلا شبكة، ومخزّناً على
 * قرص wa_media الخاص تحت invoices/{مفتاح}.pdf. المفتاح من الفاتورة (المعرّف ووقت التعديل والاعتماد) ومن الهوية (الورقة
 * والشعار والاسم) ورقم الشكل، فتعديل أي منها يعيد التصيير. قفل لكل فاتورة فلا يُصيَّر الملف نفسه مرتين بالتوازي.
 * النسخة المرسلة لعميل تُنسخ منها إلى outbound/ (للرسالة نسختها)، وهذه تُكنس بعد whatsapp.outbound.invoice_cache_days.
 */
final class InvoicePdfService
{
    public const DIR = 'invoices';

    /** يُرفع مع أي تغيير في القالب ليُصيَّر كل شيء من جديد */
    public const LAYOUT_VERSION = 1;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @return array{path: string, sha256: string, size: int} */
    public static function render(Invoice $invoice): array
    {
        $path = self::DIR.'/'.self::cacheKey($invoice).'.pdf';
        $disk = MediaArchiver::disk();
        if ($disk->exists($path)) {
            return self::describe($path);
        }

        return Cache::lock("invoice-pdf:{$invoice->id}", 90)->block(70, function () use ($invoice, $path, $disk) {
            if ($disk->exists($path)) {
                return self::describe($path);
            }
            $token = Str::uuid7();
            $htmlPath = MediaArchiver::tmpDir()."/invoice-{$token}.html";
            $pdfPath = MediaArchiver::tmpDir()."/invoice-{$token}.pdf";
            file_put_contents($htmlPath, self::html($invoice));
            try {
                HeadlessChromium::printToPdf($htmlPath, $pdfPath);
                clearstatcache(true, $pdfPath);
                $size = is_file($pdfPath) ? (int) filesize($pdfPath) : 0;
                if ($size <= 0 || $size > self::MAX_BYTES) {
                    throw new \RuntimeException($size > 0 ? 'ملف PDF الفاتورة أكبر من 5MB.' : 'ملف PDF الفاتورة فارغ.');
                }
                $head = (string) file_get_contents($pdfPath, false, null, 0, 8);
                if (!str_starts_with($head, '%PDF-')) {
                    throw new \RuntimeException('الناتج ليس ملف PDF.');
                }
                self::place($pdfPath, $path);
            } finally {
                @unlink($htmlPath);
                @unlink($pdfPath);
            }

            return self::describe($path);
        });
    }

    public static function html(Invoice $invoice): string
    {
        return View::make('invoices.pdf', ['d' => InvoicePdfData::from($invoice)->toArray(), 'brand' => self::brand()])->render();
    }

    /** «فاتورة-INV-…pdf» باسم آمن للعميل */
    public static function filename(Invoice $invoice): string
    {
        return OutboundMessage::filename('فاتورة-'.$invoice->invoice_number, 'pdf');
    }

    /** التعليق الافتراضي يبنيه الكود، والموظف يعدّله حتى 1024 حرفاً */
    public static function defaultCaption(Invoice $invoice): string
    {
        return 'فاتورتك رقم '.$invoice->invoice_number.' من '.(CompanyInfo::all()['name'] ?? '');
    }

    public static function cacheKey(Invoice $invoice): string
    {
        return sha1(implode('|', [
            $invoice->id, $invoice->updated_at?->format('U.u'), $invoice->approved_at?->format('U'), $invoice->status,
            self::LAYOUT_VERSION, (int) config('whatsapp.outbound.invoice_show_tax'), (int) config('whatsapp.outbound.invoice_letterhead'),
            Setting::get('card_letterhead_path'), Setting::get('company_logo'), CompanyInfo::all()['name'] ?? '',
        ]));
    }

    /** الورقة الرسمية كاملة خلفية (بقرار المالك) بهوامش البطاقة نفسها، وإلا الشعار وترويسة نصية */
    private static function brand(): array
    {
        $letterhead = config('whatsapp.outbound.invoice_letterhead') ? OfferCardService::dataUri(Setting::get('card_letterhead_path')) : null;

        return [
            'letterhead' => $letterhead,
            'logo' => $letterhead ? null : OfferCardService::dataUri(Setting::get('company_logo')),
            // نسب الورقة من الإعدادات (كما في البطاقة) إلى مليمترات صفحة A4
            'top_mm' => round(OfferCardService::percent(Setting::get('card_letterhead_top'), OfferCardService::LH_TOP) * 2.97, 1),
            'bottom_mm' => round(OfferCardService::percent(Setting::get('card_letterhead_bottom'), OfferCardService::LH_BOTTOM) * 2.97, 1),
            'font' => "'Noto Naskh Arabic', 'Noto Sans Arabic', 'Noto Kufi Arabic', 'DejaVu Sans', sans-serif",
        ];
    }

    /** @return array{path: string, sha256: string, size: int} */
    private static function describe(string $path): array
    {
        $disk = MediaArchiver::disk();

        return ['path' => $path, 'sha256' => hash('sha256', (string) $disk->get($path)), 'size' => (int) $disk->size($path)];
    }

    private static function place(string $tmp, string $path): void
    {
        $disk = MediaArchiver::disk();
        if (config('filesystems.disks.'.config('whatsapp.media.disk').'.driver') === 'local') {
            $target = $disk->path($path);
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0700, true);
            }
            if (!@copy($tmp, $target)) {
                throw new \RuntimeException('INVOICE_PDF_STORE_FAILED');
            }
            @chmod($target, 0600);

            return;
        }
        $stream = fopen($tmp, 'rb');
        try {
            $disk->writeStream($path, $stream);
        } finally {
            is_resource($stream) && fclose($stream);
        }
    }
}
