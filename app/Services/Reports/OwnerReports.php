<?php

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\Disbursement;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\QuoteRequest;
use App\Models\Receipt;
use App\Models\User;
use App\Models\WaConversation;
use App\Services\TravelersReport;

/**
 * تقارير المالك (E40.8): منطق واحد يستعمله المساعد الإداري على واتساب (AdminTools) وخادم MCP للقراءة.
 * بلا محادثة ولا ملاحظات للنموذج ('note' تبقى في AdminTools). المفاتيح الثلاثة تقيّد ما يخرج:
 *   withWhatsapp  عدّادات المحادثات (بيانات منصة واتساب)، وMCP يطلبها false دائماً.
 *   withPhones    أرقام العملاء، والبحث بالرقم نفسه (فلا يصير البحث وسيلة لربط رقم بعميل).
 *   withDetails   نص طلب التسعير واسم العميل (نص من المحادثة).
 * المساعد الإداري يستدعيها بالقيم القديمة (true)، فلا يتغير سلوكه (OwnerReportsParityTest).
 */
final class OwnerReports
{
    public const TRAVELERS_LIMIT = 30;

    public static function dailySummary(bool $withWhatsapp): array
    {
        $today = now()->toDateString();
        $approvedToday = Invoice::where('status', 'approved')->whereDate('approved_at', $today);
        $travelers = TravelersReport::rowsForDate($today);

        $out = [
            'date' => $today,
            'invoices_approved_today' => ['count' => (clone $approvedToday)->count(), 'total_jod' => round((float) (clone $approvedToday)->sum('total_sell_jod'), 3)],
            'invoices_created_today' => Invoice::whereDate('created_at', $today)->count(),
            'receipts_approved_today_jod' => round((float) Receipt::where('status', 'approved')->whereDate('approved_at', $today)->sum('amount_jod'), 3),
            'pending_approval' => [
                'invoices' => Invoice::where('status', 'pending')->count(),
                'receipts' => Receipt::where('status', 'pending')->count(),
                'disbursements' => Disbursement::where('status', 'pending')->count(),
            ],
            'travelers_today' => ['invoices' => count($travelers), 'pax' => array_sum(array_column($travelers, 'pax'))],
        ];
        if ($withWhatsapp) {
            $out['whatsapp'] = [
                'conversations_today' => WaConversation::whereDate('last_inbound_at', $today)->count(),
                'needs_attention' => WaConversation::where('needs_attention', true)->count(),
                'new_quote_requests' => QuoteRequest::where('status', 'new')->count(),
            ];
        }

        return $out;
    }

    /** المبيعات المعتمدة لفترة (تاريخ الفاتورة)، والربح، وأعلى خمسة بائعين */
    public static function sales(string $from, string $to): array
    {
        $rows = Invoice::where('status', 'approved')
            ->whereBetween('invoice_date', [$from, $to])
            ->get(['id', 'total_sell_jod', 'profit_jod', 'sold_by']);

        $names = Employee::with('user:id,name')->whereIn('id', $rows->pluck('sold_by')->filter()->unique())
            ->get()->mapWithKeys(fn ($e) => [$e->id => $e->user?->name ?? ('موظف #'.$e->id)]);

        $bySeller = $rows->groupBy(fn ($r) => $r->sold_by ?: 0)
            ->map(fn ($g, $k) => [
                'seller' => $k ? ($names[$k] ?? 'موظف #'.$k) : 'غير محدّد',
                'invoices' => $g->count(),
                'sales_jod' => round((float) $g->sum('total_sell_jod'), 3),
                'profit_jod' => round((float) $g->sum('profit_jod'), 3),
            ])->sortByDesc('sales_jod')->take(5)->values()->all();

        return [
            'from' => $from, 'to' => $to,
            'invoices' => $rows->count(),
            'sales_jod' => round((float) $rows->sum('total_sell_jod'), 3),
            'profit_jod' => round((float) $rows->sum('profit_jod'), 3),
            'top_sellers' => $bySeller,
        ];
    }

    /** المسافرون بتاريخ، 30 على الأكثر (count الكلي يدل على القطع) */
    public static function travelers(string $date, bool $withPhones): array
    {
        $rows = TravelersReport::rowsForDate($date);

        return [
            'date' => $date,
            'count' => count($rows),
            'pax' => array_sum(array_column($rows, 'pax')),
            'travelers' => array_map(fn ($r) => array_filter([
                'client' => $r['client'], 'phone' => $withPhones ? $r['phone'] : false, 'invoice' => $r['invoice_number'],
                'pax' => $r['pax'], 'remaining_jod' => $r['remaining'],
            ], fn ($v) => $v !== false), array_slice($rows, 0, self::TRAVELERS_LIMIT)),
        ];
    }

    public static function approvals(): array
    {
        $inv = Invoice::with('client:id,name')->where('status', 'pending')->orderBy('id')->limit(8)->get();
        $rec = Receipt::with('client:id,name')->where('status', 'pending')->orderBy('id')->limit(8)->get();
        $dis = Disbursement::where('status', 'pending')->orderBy('id')->limit(8)->get();

        return [
            'invoices' => [
                'count' => Invoice::where('status', 'pending')->count(),
                'items' => $inv->map(fn ($i) => ['number' => $i->invoice_number, 'client' => $i->client?->name, 'total_jod' => round((float) $i->total_sell_jod, 3)])->all(),
            ],
            'receipts' => [
                'count' => Receipt::where('status', 'pending')->count(),
                'items' => $rec->map(fn ($r) => ['number' => $r->receipt_number, 'client' => $r->client?->name, 'amount_jod' => round((float) $r->amount_jod, 3)])->all(),
            ],
            'disbursements' => [
                'count' => Disbursement::where('status', 'pending')->count(),
                'items' => $dis->map(fn ($d) => ['number' => $d->disbursement_number, 'description' => $d->description, 'amount' => round((float) $d->amount, 3), 'currency' => $d->currency])->all(),
            ],
        ];
    }

    /**
     * طلبات التسعير المفتوحة (جديدة أو مسعّرة). withDetails=false: رقم الطلب ونوعه وحالته وعنوان العرض العام والمسند إليه
     * ووقت إنشائه فقط، بلا اسم العميل ولا نص طلبه.
     */
    public static function openQuotes(bool $withDetails): array
    {
        $rows = QuoteRequest::with($withDetails ? ['client:id,name'] : ['offer:id,title'])->whereIn('status', ['new', 'priced'])->orderBy('id')->limit(15)->get();
        $users = User::whereIn('id', $rows->pluck('assigned_to')->filter()->unique())->pluck('name', 'id');
        $assignee = fn (QuoteRequest $q) => $q->assigned_to ? ($users[$q->assigned_to] ?? null) : 'غير مُسند';

        return [
            'count' => QuoteRequest::whereIn('status', ['new', 'priced'])->count(),
            'requests' => $rows->map(fn ($q) => $withDetails ? [
                'number' => $q->request_number,
                // اسم العميل من النظام وحده؛ الاسم الذي كتبه في واتساب لا يُعرض (E15.6)
                'customer' => $q->client?->name ?: '#'.$q->request_number,
                'type' => $q->type,
                'status' => $q->status,
                'data_quote' => mb_substr((string) $q->details, 0, 160),
                'assigned_to' => $assignee($q),
                'since' => $q->created_at?->diffForHumans(),
            ] : [
                'request_number' => $q->request_number,
                'type' => $q->type,
                'status' => $q->status,
                'offer_title' => $q->offer?->title,
                'assigned_to' => $assignee($q),
                'since' => $q->created_at?->format(DATE_ATOM),
            ])->all(),
        ];
    }

    /** بحث عن عميل بالاسم أو الرمز (وبالرقم مع withPhones): الرصيد وآخر ثلاث فواتير معتمدة. الاستعلام حرفان على الأقل */
    public static function findClient(string $q, bool $withPhones): array
    {
        $q = trim($q);
        $digits = preg_replace('/\D/', '', $q);

        $clients = Client::with('account:id,balance')
            ->where(function ($w) use ($q, $digits, $withPhones) {
                $w->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%");
                if ($withPhones && strlen($digits) >= 6) {
                    $w->orWhere('phone', 'like', '%'.substr($digits, -9).'%');
                }
            })
            ->limit(5)->get();

        return [
            'count' => $clients->count(),
            'clients' => $clients->map(fn ($c) => array_filter([
                'name' => $c->name, 'code' => $c->code, 'phone' => $withPhones ? $c->phone : false,
                // بلا حساب محاسبي: لا صفر يُقرأ «مسدّد» (E12.7)
                'balance_jod' => $c->account ? round((float) $c->account->balance, 3) : null,
                'balance_status' => $c->account ? 'ok' : 'no_account',
                'last_invoices' => Invoice::where('client_id', $c->id)->where('status', 'approved')
                    ->orderByDesc('invoice_date')->limit(3)->get(['invoice_number', 'invoice_date', 'trip_date', 'total_sell_jod'])
                    ->map(fn ($i) => [
                        'number' => $i->invoice_number, 'date' => $i->invoice_date?->toDateString(),
                        'trip' => $i->trip_date?->toDateString(), 'total_jod' => round((float) $i->total_sell_jod, 3),
                    ])->all(),
            ], fn ($v) => $v !== false))->all(),
        ];
    }
}
