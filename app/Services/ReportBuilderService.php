<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * يُجهّز مجموعة بيانات منشئ التقارير: بنود الخدمات في الفواتير المعتمدة،
 * مع توزيع مبيع الفاتورة على بنودها نسبةً إلى التكلفة (لأن سعر البيع يُخزَّن
 * على مستوى الفاتورة لا البند)، بنفس منطق ترحيل القيد المحاسبي.
 * التجميع والفلترة يجريان في الواجهة (البيانات صغيرة الحجم) لتفاعل فوري.
 */
class ReportBuilderService
{
    private static function rate(): float
    {
        return (float) config('accounting.sar_to_jod', 0.19);
    }

    public static function dataset(): array
    {
        // أنواع الخدمات التي ظهرت فعلاً في بنود الفواتير المعتمدة
        $services = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->join('services as sv', 'sv.id', '=', 'ii.service_id')
            ->where('i.status', 'approved')->where('ii.item_type', 'service')
            ->distinct()->select('sv.id', 'sv.code', 'sv.name')->get();

        // إجمالي تكلفة بنود الخدمات لكل فاتورة (مقام التوزيع)
        $invCost = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->where('i.status', 'approved')->where('ii.item_type', 'service')
            ->groupBy('ii.invoice_id')
            ->select('ii.invoice_id', DB::raw('SUM(ii.total_cost_jod) as cj'))
            ->pluck('cj', 'ii.invoice_id');

        $rows = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->where('i.status', 'approved')->where('ii.item_type', 'service')
            ->orderBy('i.invoice_date')->orderBy('i.id')
            ->select(
                'i.invoice_date as d', 'ii.invoice_id as iid', 'i.invoice_number as n',
                'ii.agent_id as a', 'i.client_id as c', 'ii.service_id as s', 'ii.quantity as q',
                'ii.total_cost_sar as co', 'ii.total_cost_jod as coj', 'i.total_sell_jod as isell'
            )->get();

        $facts = [];
        $ags = [];
        $cls = [];
        foreach ($rows as $r) {
            $den = (float) ($invCost[$r->iid] ?? 0);
            // المبيع المنسوب للبند = مبيع الفاتورة × (تكلفة البند ÷ إجمالي تكلفة بنودها)
            $se = $den > 0 ? round(((float) $r->isell) * ((float) $r->coj) / $den, 2) : 0.0;
            $facts[] = [
                'd'  => substr((string) $r->d, 0, 10),
                'ii' => (int) $r->iid,
                'n'  => $r->n,
                'a'  => (int) $r->a,
                'c'  => (int) $r->c,
                's'  => (int) $r->s,
                'q'  => (int) $r->q,
                'co' => round((float) $r->co, 1),
                'se' => $se,
            ];
            $ags[(int) $r->a] = 1;
            $cls[(int) $r->c] = 1;
        }

        // الأطراف التي لها نشاط فقط، مع الرصيد بالدينار من دفتر القيود
        $agents = DB::table('agents as ag')
            ->leftJoin('accounts as a', 'a.id', '=', 'ag.account_id')
            ->whereIn('ag.id', array_keys($ags))
            ->select('ag.id', 'ag.code', 'ag.name', DB::raw('ROUND(COALESCE(a.balance,0),2) as bal'))
            ->orderBy('ag.code')->get();

        $clients = DB::table('clients as cl')
            ->leftJoin('accounts as a', 'a.id', '=', 'cl.account_id')
            ->whereIn('cl.id', array_keys($cls))
            ->select('cl.id', 'cl.code', 'cl.name', DB::raw('ROUND(COALESCE(a.balance,0),2) as bal'))
            ->orderBy('cl.code')->get();

        // سندات القبض المعتمدة (لقسم الموقف المالي — جانب العميل)
        $receipts = DB::table('receipts')->where('status', 'approved')
            ->select(DB::raw('substr(receipt_date,1,10) as d'), 'client_id as c', DB::raw('ROUND(amount_jod,2) as jod'))
            ->get();

        return [
            'snapshot' => now()->format('Y-m-d H:i:s'),
            'today'    => now()->toDateString(),
            'rate'     => self::rate(),
            'services' => $services,
            'agents'   => $agents,
            'clients'  => $clients,
            'facts'    => $facts,
            'receipts' => $receipts,
        ];
    }
}
