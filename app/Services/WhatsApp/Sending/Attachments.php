<?php

namespace App\Services\WhatsApp\Sending;

use App\Models\Invoice;
use App\Models\Receipt;
use App\Services\Invoices\InvoicePdfService;
use App\Services\Receipts\ReceiptPdfService;

/**
 * مرفق رسالة نظام يُصيَّر لحظة الإرسال (لا عند الجدولة)، فيصل العميل آخر نسخة من الفاتورة أو السند.
 * المواصفة في wa_outbox.payload.attachment: {generator: invoice_pdf|receipt_pdf, id}.
 *
 * @return array{path: string, filename: string, mime: string, sha256?: string, size?: int}|null المسار على قرص wa_media
 */
final class Attachments
{
    public static function make(array $spec): ?array
    {
        $id = (int) ($spec['id'] ?? 0);

        return match ((string) ($spec['generator'] ?? '')) {
            'invoice_pdf' => ($inv = Invoice::find($id)) ? InvoicePdfService::render($inv) + ['filename' => InvoicePdfService::filename($inv), 'mime' => 'application/pdf'] : null,
            'receipt_pdf' => ($r = Receipt::find($id)) ? ReceiptPdfService::render($r) + ['filename' => ReceiptPdfService::filename($r), 'mime' => 'application/pdf'] : null,
            default => null,
        };
    }
}
