<?php

namespace App\Services\Receipts;

use App\Models\Receipt;
use App\Services\CompanyInfo;
use App\Services\Invoices\InvoicePdfService;
use App\Services\Rendering\HeadlessChromium;
use App\Services\WhatsApp\Media\MediaArchiver;
use App\Services\WhatsApp\Outbound\OutboundMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * سند القبض PDF للعميل (رسالة النظام receipt.approved): على نمط InvoicePdfService تماماً. قالب receipts.pdf بهوية
 * الفاتورة نفسها (الورقة الرسمية أو الشعار)، مطبوعاً بكروميوم بلا شبكة، ومخزّناً على قرص wa_media تحت receipts/{مفتاح}.pdf.
 */
final class ReceiptPdfService
{
    public const DIR = 'receipts';

    public const LAYOUT_VERSION = 1;

    /** @return array{path: string, sha256: string, size: int} */
    public static function render(Receipt $receipt): array
    {
        $path = self::DIR.'/'.self::cacheKey($receipt).'.pdf';
        $disk = MediaArchiver::disk();
        if ($disk->exists($path)) {
            return self::describe($path);
        }

        return Cache::lock("receipt-pdf:{$receipt->id}", 90)->block(70, function () use ($receipt, $path, $disk) {
            if ($disk->exists($path)) {
                return self::describe($path);
            }
            $token = Str::uuid7();
            $htmlPath = MediaArchiver::tmpDir()."/receipt-{$token}.html";
            $pdfPath = MediaArchiver::tmpDir()."/receipt-{$token}.pdf";
            file_put_contents($htmlPath, self::html($receipt));
            try {
                HeadlessChromium::printToPdf($htmlPath, $pdfPath);
                clearstatcache(true, $pdfPath);
                $size = is_file($pdfPath) ? (int) filesize($pdfPath) : 0;
                if ($size <= 0 || $size > InvoicePdfService::MAX_BYTES || !str_starts_with((string) file_get_contents($pdfPath, false, null, 0, 8), '%PDF-')) {
                    throw new \RuntimeException('تعذّر تصيير سند القبض PDF.');
                }
                $disk->put($path, (string) file_get_contents($pdfPath));
            } finally {
                @unlink($htmlPath);
                @unlink($pdfPath);
            }

            return self::describe($path);
        });
    }

    public static function html(Receipt $receipt): string
    {
        $receipt->loadMissing('client:id,name,code');

        return View::make('receipts.pdf', ['r' => [
            'number' => $receipt->receipt_number,
            'date' => optional($receipt->receipt_date)->format('Y-m-d') ?? (string) $receipt->receipt_date,
            'client' => $receipt->client?->name,
            'client_code' => $receipt->client?->code,
            'amount' => number_format((float) $receipt->amount_jod, 3),
            'method' => $receipt->payment_method,
            'reference' => $receipt->reference_number,
            'notes' => $receipt->notes,
        ], 'company' => CompanyInfo::all(), 'brand' => InvoicePdfService::brand()])->render();
    }

    public static function filename(Receipt $receipt): string
    {
        return OutboundMessage::filename('سند-قبض-'.$receipt->receipt_number, 'pdf');
    }

    public static function cacheKey(Receipt $receipt): string
    {
        return sha1(implode('|', [$receipt->id, $receipt->updated_at?->format('U.u'), $receipt->status, self::LAYOUT_VERSION, CompanyInfo::all()['name'] ?? '']));
    }

    /** @return array{path: string, sha256: string, size: int} */
    private static function describe(string $path): array
    {
        $disk = MediaArchiver::disk();

        return ['path' => $path, 'sha256' => hash('sha256', (string) $disk->get($path)), 'size' => (int) $disk->size($path)];
    }
}
