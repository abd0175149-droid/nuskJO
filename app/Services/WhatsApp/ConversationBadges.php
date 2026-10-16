<?php

namespace App\Services\WhatsApp;

use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * شارات مشتقة من النظام لسطر المحادثة في القائمة (E29): تُحسب في الخادم ولا تُخزَّن، لصفحة القائمة كلها بعدد ثابت
 * من الاستعلامات (ثلاثة على الأكثر مهما كبرت الصفحة).
 * - «طلب تسعير مفتوح» (new أو priced)، بسياج طلبات التسعير (QuoteRequest::visibleTo) حين يُفعَّل.
 * - «سفر خلال أيام» و«فاتورة بانتظار الاعتماد» لمن يملك invoices.view.
 * - «عليه رصيد» لمن يملك accounting.view، بلا مبلغ.
 * المالية للمحادثة ذات الرقم الموثوق وحدها (financialClientId، E11): لا شارة مالية لمحادثة بلا رقم.
 * الكاش 60 ثانية بمفتاح المحادثات وبصمة الصلاحيات والمستخدم، فلا تتسرب شارة بين مستخدمين.
 */
final class ConversationBadges
{
    public const TRIP_DAYS = 14;

    private const TTL_SECONDS = 60;

    /**
     * @param  Collection<int, WaConversation>  $convs
     * @return array<int, list<array{key: string, label: string}>>
     */
    public static function forPage(Collection $convs, User $u): array
    {
        $ids = $convs->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($ids === []) {
            return [];
        }
        $clients = [];
        foreach ($convs as $c) {
            if ($clientId = $c->financialClientId()) {
                $clients[(int) $c->id] = $clientId;
            }
        }
        $can = ['invoices' => $u->can('invoices.view'), 'balance' => $u->can('accounting.view')];
        $signature = $u->id.'|'.(int) $can['invoices'].(int) $can['balance'].'|'.(int) (bool) config('services.whatsapp.fence_quotes').'|'.now()->toDateString();
        $key = 'wa:badges:'.md5(implode(',', $ids).'|'.json_encode($clients).'|'.$signature);

        return Cache::remember($key, self::TTL_SECONDS, fn () => self::compute($ids, $clients, $can, $u));
    }

    /** نصوص الشارات ثابتة يبنيها الخادم، والمفاتيح للأيقونات في الواجهة */
    private static function compute(array $ids, array $clients, array $can, User $u): array
    {
        $out = [];
        $add = function (int $convId, string $key, string $label) use (&$out) {
            $out[$convId][] = ['key' => $key, 'label' => $label];
        };

        // 1) طلب تسعير مفتوح
        $quotes = QuoteRequest::query()->visibleTo($u)->whereIn('quote_requests.conversation_id', $ids)
            ->whereIn('quote_requests.status', ['new', 'priced'])
            ->selectRaw('quote_requests.conversation_id, count(*) as n')->groupBy('quote_requests.conversation_id')
            ->pluck('n', 'conversation_id');
        foreach ($quotes as $convId => $n) {
            $add((int) $convId, 'quote', 'طلب تسعير مفتوح');
        }

        $clientIds = array_values(array_unique($clients));
        if ($clientIds === []) {
            return $out;
        }

        // 2+3) سفر قريب وفاتورة بانتظار الاعتماد: استعلام واحد لكل العملاء
        $invoices = [];
        if ($can['invoices']) {
            $from = today()->toDateString();
            $to = today()->addDays(self::TRIP_DAYS)->toDateString();
            $invoices = DB::table('invoices')->whereNull('deleted_at')->whereIn('client_id', $clientIds)->whereIn('status', ['pending', 'approved'])
                // date(): العمود يُخزَّن «Y-m-d» أو «Y-m-d 00:00:00» بحسب من كتبه، والمقارنة النصية تُسقط اليوم الأخير
                ->selectRaw("client_id, min(case when date(trip_date) >= ? and date(trip_date) <= ? then date(trip_date) end) as trip, sum(case when status = 'pending' then 1 else 0 end) as pending", [$from, $to])
                ->groupBy('client_id')->get()->keyBy('client_id');
        }

        // 4) عليه رصيد: المصدر نفسه الذي يقرأ منه البوت (رصيد حساب العميل)، بلا مبلغ
        $owing = $can['balance']
            ? DB::table('clients')->join('accounts', 'accounts.id', '=', 'clients.account_id')
                ->whereIn('clients.id', $clientIds)->where('accounts.balance', '>', 0.0005)->pluck('clients.id')->map(fn ($id) => (int) $id)->flip()->all()
            : [];

        foreach ($clients as $convId => $clientId) {
            $row = $invoices[$clientId] ?? null;
            if ($row && $row->trip) {
                $add($convId, 'trip', self::tripLabel((int) today()->diffInDays(Carbon::parse($row->trip), false)));
            }
            if ($row && (int) $row->pending > 0) {
                $add($convId, 'invoice_pending', 'فاتورة بانتظار الاعتماد');
            }
            if (isset($owing[$clientId])) {
                $add($convId, 'balance', 'عليه رصيد');
            }
        }

        return $out;
    }

    public static function tripLabel(int $days): string
    {
        return match (true) {
            $days <= 0 => 'سفر اليوم',
            $days === 1 => 'سفر غداً',
            $days === 2 => 'سفر بعد يومين',
            $days <= 10 => "سفر بعد {$days} أيام",
            default => "سفر بعد {$days} يوماً",
        };
    }
}
