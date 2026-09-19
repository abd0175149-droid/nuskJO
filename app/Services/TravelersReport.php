<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Receipt;
use Illuminate\Support\Collection;

/**
 * منطق تقرير المسافرين (مشترك بين صفحة "المسافرون بتاريخ" وبطاقة "مسافرو اليوم").
 */
class TravelersReport
{
    /**
     * المتبقي لكل فاتورة بتوزيع دفعات العميل (سندات القبض المعتمدة) على فواتيره
     * من الأقدم للأحدث (FIFO). يعيد [invoice_id => remaining].
     */
    public static function remainingFifo(Collection $clientIds): array
    {
        $clientIds = $clientIds->filter()->values();
        if ($clientIds->isEmpty()) {
            return [];
        }

        $invoices = Invoice::query()
            ->whereIn('client_id', $clientIds)
            ->where('status', 'approved')
            ->orderBy('invoice_date')->orderBy('id')
            ->get(['id', 'client_id', 'total_sell_jod']);

        $paidByClient = Receipt::query()
            ->whereIn('client_id', $clientIds)
            ->where('status', 'approved')
            ->selectRaw('client_id, SUM(amount_jod) as paid')
            ->groupBy('client_id')
            ->pluck('paid', 'client_id');

        $remaining = [];
        $pool = [];
        foreach ($invoices as $inv) {
            $cid = $inv->client_id;
            if (!array_key_exists($cid, $pool)) {
                $pool[$cid] = (float) ($paidByClient[$cid] ?? 0);
            }
            $total = (float) $inv->total_sell_jod;
            $applied = min($pool[$cid], $total);
            $pool[$cid] -= $applied;
            $remaining[$inv->id] = round($total - $applied, 3);
        }

        return $remaining;
    }

    /**
     * صفوف المسافرين لتاريخ محدد — بنفس شكل بيانات صفحة "المسافرون بتاريخ".
     */
    public static function rowsForDate(string $date): array
    {
        $invoices = Invoice::query()
            ->with(['client:id,name,code,phone', 'items:id,invoice_id,agent_id,quantity', 'items.agent:id,name,code', 'creator:id,name'])
            ->where('status', 'approved')
            ->whereDate('trip_date', $date)
            ->orderBy('invoice_number')
            ->get();

        $remainingByInvoice = self::remainingFifo($invoices->pluck('client_id')->unique()->filter());

        return $invoices->map(fn ($i) => [
            'invoice_number' => $i->invoice_number,
            'client'  => $i->client?->name,
            'phone'   => $i->client_phone ?: $i->client?->phone,
            'trip_date' => $i->trip_date?->toDateString(),
            'agents'  => $i->items->map(fn ($it) => $it->agent?->name)->filter()->unique()->values(),
            'pax'     => (int) $i->items->sum('quantity'),
            'total'   => round((float) $i->total_sell_jod, 3),
            'remaining' => $remainingByInvoice[$i->id] ?? round((float) $i->total_sell_jod, 3),
            'employee' => $i->creator?->name ?: '—',
        ])->values()->all();
    }
}
