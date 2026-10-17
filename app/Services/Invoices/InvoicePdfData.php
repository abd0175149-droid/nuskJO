<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\OfferOption;
use App\Services\CompanyInfo;

/**
 * بيانات فاتورة PDF للعميل (E32.8) بقائمة سماح: حقول مسماة تُنسخ واحداً واحداً، ولا يمر النموذج إلى القالب.
 * لا يُنسخ أبداً: الوكيل (items.agent وagent_id) وأي تكلفة أو ربح (total_cost_* وprofit_* وunit_price_* وservices_cost_sar
 * وcost_original وfx_rate) وoriginal_values ولقطة offer_lines الخام والملاحظات (قد تكون داخلية؛ القرار للمالك).
 *
 * فاتورة العرض تُبنى من جانب البيع في لقطتها (السطر والاختيار والغرفة وعدد المسافرين وسعر الفرد، وما بيع خارج العرض،
 * والخصم) كما في صفحة الطباعة، لا من بنودها الداخلية التي تحمل الوكلاء والتكاليف.
 */
final class InvoicePdfData
{
    public const KEYS = ['invoice_number', 'invoice_date', 'trip_date', 'status', 'status_label', 'client', 'offer', 'items', 'discount_jod', 'total_jod', 'company'];

    public const ITEM_KEYS = ['type_label', 'description', 'quantity', 'sell_price_jod', 'total_sell_jod'];

    public const STATUS_LABELS = ['pending' => 'معلقة', 'approved' => 'معتمدة', 'rejected' => 'مرفوضة', 'editing' => 'تحت التعديل'];

    private const TYPE_LABELS = ['service' => 'خدمة', 'offer' => 'عرض', 'discount' => 'خصم', 'violation' => 'مخالفة'];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    public static function from(Invoice $invoice): self
    {
        $invoice->loadMissing(['client:id,name,code', 'offer:id,title,destination,trip_date']);
        $snapshot = is_array($invoice->offer_lines) && is_array($invoice->offer_lines['lines'] ?? null) ? $invoice->offer_lines : null;
        $discount = $snapshot !== null ? round((float) ($snapshot['discount'] ?? 0), 3) : 0.0;

        return new self([
            'invoice_number' => (string) $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
            'trip_date' => $invoice->trip_date?->format('Y-m-d'),
            'status' => (string) $invoice->status,
            'status_label' => self::STATUS_LABELS[$invoice->status] ?? (string) $invoice->status,
            'client' => ['name' => (string) ($invoice->client?->name ?? ''), 'code' => $invoice->client?->code],
            'offer' => $invoice->offer ? [
                'title' => (string) $invoice->offer->title,
                'destination' => $invoice->offer->destination,
                'trip_date' => $invoice->offer->trip_date?->format('Y-m-d'),
            ] : null,
            'items' => $snapshot !== null ? self::offerItems($snapshot) : self::regularItems($invoice),
            'discount_jod' => $discount > 0 ? $discount : null,
            'total_jod' => round((float) ($invoice->total_jod ?? $invoice->total_sell_jod ?? 0), 3),
            'company' => self::company(),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return list<array<string, mixed>> */
    private static function offerItems(array $snapshot): array
    {
        $rooms = OfferOption::ROOM_TYPES;
        $items = [];
        foreach ((array) $snapshot['lines'] as $line) {
            $choice = implode(' · ', array_filter(array_map(fn ($l) => is_array($l) ? (string) ($l['option'] ?? '') : '', (array) ($line['labels'] ?? []))));
            $room = isset($line['room']) && $line['room'] !== '' ? (string) ($rooms[$line['room']] ?? $line['room']) : '';
            $pax = (int) ($line['pax'] ?? 0);
            $sell = round((float) ($line['sell_pp'] ?? 0), 3);
            $items[] = self::item('offer', implode(' — ', array_filter([(string) ($snapshot['offer_title'] ?? ''), $choice, $room], 'strlen')), $pax, $sell, round($sell * $pax, 3));
        }
        foreach ((array) ($snapshot['extras'] ?? []) as $extra) {
            $qty = (int) ($extra['qty'] ?? 0);
            $sell = round((float) ($extra['sell'] ?? 0), 3);
            $items[] = self::item('service', (string) ($extra['description'] ?? ''), $qty, $sell, round($sell * $qty, 3));
        }

        return $items;
    }

    /** الفاتورة العادية: البيان والعدد، وسعر البيع للسطر إن وُجد (البنود القائمة تحمل التكلفة وحدها، والإجمالي من الفاتورة) */
    private static function regularItems(Invoice $invoice): array
    {
        return $invoice->items()->get(['id', 'item_type', 'description', 'quantity', 'sell_price_jod', 'total_sell_jod', 'sort_order'])
            ->map(fn ($i) => self::item((string) $i->item_type, (string) $i->description, (int) $i->quantity,
                (float) $i->sell_price_jod > 0 ? round((float) $i->sell_price_jod, 3) : null,
                (float) $i->total_sell_jod > 0 ? round((float) $i->total_sell_jod, 3) : null))
            ->values()->all();
    }

    private static function item(string $type, string $description, int $quantity, ?float $sell, ?float $total): array
    {
        return [
            'type_label' => self::TYPE_LABELS[$type] ?? self::TYPE_LABELS['violation'],
            'description' => $description,
            'quantity' => $quantity,
            'sell_price_jod' => $sell,
            'total_sell_jod' => $total,
        ];
    }

    /** هوية الشركة للترويسة النصية؛ الرقم الضريبي بقرار المالك (whatsapp.outbound.invoice_show_tax) */
    private static function company(): array
    {
        $c = CompanyInfo::all();

        return array_filter([
            'name' => $c['name'] ?? null,
            'name_en' => $c['name_en'] ?? null,
            'phone' => $c['phone'] ?? null,
            'email' => $c['email'] ?? null,
            'address' => $c['address'] ?? null,
            'tax' => config('whatsapp.outbound.invoice_show_tax') ? ($c['tax'] ?? null) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
