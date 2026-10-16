<?php

namespace App\Services\WhatsApp;

use App\Models\Invoice;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use Illuminate\Support\Facades\Cache;

/**
 * بطاقة حقائق رأس المحادثة (E29): الملاحظة والرسائل المثبتة، والتصنيفات، وما يلزم الموظف من النظام: العميل، وأقرب سفر،
 * وآخر فاتورة، وطلب التسعير المفتوح، والرصيد. كل تفصيل بصلاحيته، والمالي للمحادثة ذات الرقم الموثوق وحدها (E11).
 * مسار مستقل لا حقل في خيط الرسائل: الاستطلاع كل 15 ثانية لا يعيد حساباً مالياً. الجزء المالي في كاش 60 ثانية
 * بمفتاح المحادثة والمستخدم وصلاحياته؛ التثبيت والتصنيفات تُقرأ في كل طلب فتظهر التعديلات فوراً.
 */
final class ConversationFacts
{
    private const TTL_SECONDS = 60;

    public const INVOICE_STATUS = ['draft' => 'مسودة', 'pending' => 'بانتظار الاعتماد', 'approved' => 'معتمدة', 'rejected' => 'مرفوضة', 'editing' => 'تحت التعديل'];

    public const QUOTE_STATUS = ['new' => 'جديد', 'priced' => 'مسعّر', 'sent' => 'أُرسل السعر'];

    public static function for(WaConversation $c, User $u): array
    {
        $can = [
            'clients' => $u->can('clients.view'), 'invoices' => $u->can('invoices.view'),
            'quotes' => $u->can('quotes.view'), 'balance' => $u->can('accounting.view'),
        ];
        $key = 'wa:facts:'.$c->id.':'.$u->id.':'.md5(json_encode($can).'|'.$c->financialClientId().'|'.(int) (bool) config('services.whatsapp.fence_quotes'));
        $erp = Cache::remember($key, self::TTL_SECONDS, fn () => self::erp($c, $u, $can));

        return [
            'pinned' => ThreadPins::enabled() ? ThreadPins::summary($c) : null,
            'labels' => LabelService::enabled() ? (LabelService::chipsFor([$c->id])[$c->id] ?? []) : null,
        ] + $erp;
    }

    private static function erp(WaConversation $c, User $u, array $can): array
    {
        $out = ['client' => null, 'next_trip' => null, 'last_invoice' => null, 'open_quote' => null, 'balance' => null, 'hint' => null];

        // طلب التسعير المفتوح للمحادثة نفسها (لا مالي فيه)، بسياج طلبات التسعير حين يُفعَّل
        $quote = QuoteRequest::query()->visibleTo($u)->where('quote_requests.conversation_id', $c->id)
            ->whereIn('quote_requests.status', array_keys(self::QUOTE_STATUS))->orderByDesc('quote_requests.id')->first();
        if ($quote) {
            $out['open_quote'] = ['request_number' => $quote->request_number, 'status' => $quote->status, 'status_label' => self::QUOTE_STATUS[$quote->status] ?? $quote->status]
                + ($can['quotes'] ? ['id' => $quote->id, 'url' => '/quote-requests?status=all&search='.rawurlencode((string) $quote->request_number)] : []);
        }

        $clientId = $c->financialClientId();
        $client = $clientId ? $c->client : null;
        if (!$client) {
            $out['hint'] = 'المحادثة غير مربوطة بعميل';

            return $out;
        }

        $out['client'] = ['name' => $client->name, 'code' => $client->code]
            + ($can['clients'] ? ['url' => '/clients?search='.rawurlencode((string) $client->code)] : []);

        // أقرب سفر قادم: التاريخ للجميع، ورقم الفاتورة ورابطها لمن يملك invoices.view
        $trip = Invoice::where('client_id', $client->id)->whereIn('status', ['pending', 'approved'])
            ->whereNotNull('trip_date')->whereRaw('date(trip_date) >= ?', [today()->toDateString()])
            ->orderBy('trip_date')->orderBy('id')->first(['id', 'invoice_number', 'trip_date']);
        if ($trip) {
            $out['next_trip'] = ['date' => $trip->trip_date?->toDateString()]
                + ($can['invoices'] ? ['invoice_number' => $trip->invoice_number, 'invoice_id' => $trip->id, 'url' => '/invoices?highlight='.$trip->id] : []);
        }

        if ($can['invoices']) {
            $last = Invoice::where('client_id', $client->id)->orderByDesc('invoice_date')->orderByDesc('id')->first(['id', 'invoice_number', 'status']);
            if ($last) {
                $out['last_invoice'] = ['number' => $last->invoice_number, 'status' => $last->status,
                    'status_label' => self::INVOICE_STATUS[$last->status] ?? $last->status, 'url' => '/invoices?highlight='.$last->id];
            }
        }

        // الرصيد من المصدر الذي يقرأ منه البوت (رصيد حساب العميل)، لمن يملك accounting.view وحده
        if ($can['balance'] && $client->account) {
            $balance = round((float) $client->account->balance, 3);
            $out['balance'] = [
                'amount' => number_format(abs($balance), 3, '.', ''),
                'currency' => 'JOD',
                'direction' => $balance > 0 ? 'عليه' : ($balance < 0 ? 'له' : null),
            ];
        }

        return $out;
    }
}
