<?php

namespace App\Services\WhatsApp;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use App\Services\OfferPricing;
use App\Support\FeatureFlags;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * اللوحة الجانبية لصندوق المحادثات: كل ما يلزم الموظف عن صاحب المحادثة في مكان واحد —
 * رحلاته (موعدها وعرضها وفنادقها ومسافروها)، وفواتيره، وطلبات تسعيره، وملاحظات البوت والنظام، وحقائق المحادثة.
 *
 * الهوية: العميل المربوط بالمحادثة، وإلا العميل الذي يطابق رقمها، ومعهما الفواتير المسجّلة على الرقم نفسه.
 * لا شيء من النظام لمحادثة بلا رقم ظاهر (اسم مستخدم وحده): الرقم وحده هوية موثوقة من ميتا (E11).
 * الصلاحيات: تفاصيل الرحلة (التاريخ والفنادق والمسافرون) لكل من يرى المحادثة لأنها خدمة العميل نفسها؛
 * رقم الفاتورة ومبالغها لمن يملك invoices.view، والرصيد لمن يملك accounting.view، ورابط العرض لمن يملك offers.view.
 * لا تكلفة ولا وكيل في أي حقل. الجزء الثقيل في كاش 60 ثانية بمفتاح المحادثة والمستخدم وصلاحياته.
 */
final class ConversationPanel
{
    private const TTL_SECONDS = 60;

    private const LIVE = ['pending', 'approved', 'editing'];

    public const INVOICE_STATUS = ['draft' => 'مسودة', 'pending' => 'بانتظار الاعتماد', 'approved' => 'معتمدة', 'rejected' => 'مرفوضة', 'editing' => 'تحت التعديل'];

    public static function enabled(): bool
    {
        return FeatureFlags::on('conversation_panel');
    }

    public static function for(WaConversation $c, User $u): array
    {
        $can = [
            'invoices' => $u->can('invoices.view'), 'balance' => $u->can('accounting.view'),
            'clients' => $u->can('clients.view'), 'offers' => $u->can('offers.view'), 'quotes' => $u->can('quotes.view'),
        ];
        $key = 'wa:panel:'.$c->id.':'.$u->id.':'.md5(json_encode($can).'|'.$c->client_id.'|'.$c->phone.'|'.(int) (bool) config('services.whatsapp.fence_quotes'));

        return Cache::remember($key, self::TTL_SECONDS, fn () => self::build($c, $u, $can)) + [
            // الملاحظات والحقائق تُقرأ في كل طلب: تظهر إضافات البوت فوراً
            'notes' => self::notes($c),
            'conversation' => self::conversation($c),
        ];
    }

    private static function build(WaConversation $c, User $u, array $can): array
    {
        $out = ['identity' => ['trusted' => $c->hasPhone(), 'linked' => (bool) $c->client_id, 'hint' => null],
            'clients' => [], 'trips' => [], 'past_trips' => [], 'invoices' => null, 'summary' => null, 'balance' => null, 'quotes' => self::quotes($c, $u, $can)];

        if (!$c->hasPhone()) {
            $out['identity']['hint'] = 'رقم العميل غير ظاهر، فلا تُعرض بياناته من النظام.';

            return $out;
        }

        $clients = self::clients($c);
        $out['clients'] = $clients->map(fn ($x) => ['name' => $x['client']->name, 'code' => $x['client']->code, 'source' => $x['source']]
            + ($can['clients'] ? ['url' => '/clients?search='.rawurlencode((string) $x['client']->code)] : []))->values()->all();
        if ($clients->isEmpty()) {
            $out['identity']['hint'] = 'لا عميل مسجّل بهذا الرقم. اربط المحادثة بعميل لتظهر فواتيره ورحلاته.';
        }

        $invoices = self::invoices($c, $clients->pluck('client.id')->all());
        [$upcoming, $past] = self::trips($invoices, $can);
        $out['trips'] = $upcoming;
        $out['past_trips'] = $past;

        if ($can['invoices']) {
            $out['invoices'] = $invoices->map(fn (Invoice $i) => [
                'id' => $i->id,
                'number' => $i->invoice_number,
                'date' => $i->invoice_date?->toDateString(),
                'status' => $i->status,
                'status_label' => self::INVOICE_STATUS[$i->status] ?? $i->status,
                'trip_date' => $i->trip_date?->toDateString(),
                'total' => number_format((float) ($i->total_sell_jod ?: $i->total_jod), 3, '.', ''),
                'offer' => $i->offer_id ? ($i->offer_lines['offer_title'] ?? $i->offer?->title) : null,
                'seats' => $i->offer_id ? (int) $i->offer_seats : null,
                'url' => '/invoices?highlight='.$i->id,
            ])->values()->all();
            $approved = $invoices->where('status', 'approved');
            $out['summary'] = [
                'count' => $invoices->count(),
                'approved_count' => $approved->count(),
                'approved_total' => number_format((float) $approved->sum(fn ($i) => (float) ($i->total_sell_jod ?: $i->total_jod)), 3, '.', ''),
                'pending_count' => $invoices->whereIn('status', ['pending', 'editing'])->count(),
            ];
        }

        $main = $clients->first()['client'] ?? null;
        if ($can['balance'] && $main?->account) {
            $balance = round((float) $main->account->balance, 3);
            $out['balance'] = ['amount' => number_format(abs($balance), 3, '.', ''), 'currency' => 'JOD',
                'direction' => $balance > 0 ? 'عليه' : ($balance < 0 ? 'له' : null), 'client' => $main->name];
        }

        return $out;
    }

    /** @return Collection<int, array{client: Client, source: string}> المربوط أولاً، ثم من يطابق الرقم */
    private static function clients(WaConversation $c): Collection
    {
        $out = collect();
        if ($c->client_id && $c->client) {
            $out->push(['client' => $c->client, 'source' => 'linked']);
        }
        $byPhone = Client::findByPhone($c->phone);
        if ($byPhone && !$out->contains(fn ($x) => $x['client']->id === $byPhone->id)) {
            $out->push(['client' => $byPhone, 'source' => 'phone']);
        }

        return $out;
    }

    /** فواتير العملاء المطابقين، ومعها الفواتير المسجّلة على رقم المحادثة نفسه (لعميل آخر بالرقم ذاته) */
    private static function invoices(WaConversation $c, array $clientIds): Collection
    {
        $tail = PhoneNormalizer::tail($c->phone);
        $candidates = Invoice::query()
            ->with(['offer:id,title,is_ready,trip_date,return_date,travel_mode,destination,pricing_mode,nights'])
            ->where(function ($q) use ($clientIds, $tail) {
                $q->whereIn('client_id', $clientIds ?: [0]);
                if (strlen($tail) === 9) {
                    // الرقم في الفاتورة كما كتبه البائع: تُزال الفواصل الشائعة قبل مقارنة الذيل
                    $q->orWhereRaw("replace(replace(replace(replace(client_phone, ' ', ''), '-', ''), '(', ''), ')', '') like ?", ['%'.$tail]);
                }
            })
            ->where('status', '!=', 'rejected')
            ->orderByDesc('invoice_date')->orderByDesc('id')->limit(60)->get();

        // «like» على آخر تسعة أرقام مرشّح فقط: المطابقة الحقيقية بالتطبيع الكامل
        return $candidates->filter(fn (Invoice $i) => in_array($i->client_id, $clientIds, true)
            || PhoneNormalizer::same($i->client_phone, $c->phone))->values();
    }

    /** @return array{0: array, 1: array} القادمة (الأقرب أولاً) والسابقة (الأحدث أولاً، خمس) */
    private static function trips(Collection $invoices, array $can): array
    {
        $today = Carbon::today();
        $trips = $invoices->filter(fn (Invoice $i) => in_array($i->status, self::LIVE, true) && $i->trip_date)
            ->map(fn (Invoice $i) => self::trip($i, $today, $can));

        $upcoming = $trips->filter(fn ($t) => $t['phase'] !== 'past')->sortBy('trip_date')->values()->all();
        $past = $trips->filter(fn ($t) => $t['phase'] === 'past')->sortByDesc('trip_date')->take(5)->values()->all();

        return [$upcoming, $past];
    }

    private static function trip(Invoice $i, Carbon $today, array $can): array
    {
        $offer = $i->offer;
        $start = $i->trip_date->copy()->startOfDay();
        $end = ($offer?->is_ready && $offer->return_date) ? $offer->return_date->copy()->startOfDay() : null;
        $phase = $start->gt($today) ? 'upcoming' : (($end && $end->gte($today)) || $start->eq($today) ? 'travelling' : 'past');

        $t = [
            'key' => 'inv-'.$i->id,
            'trip_date' => $start->toDateString(),
            'return_date' => $end?->toDateString(),
            'nights' => $end ? (int) $start->diffInDays($end) : null,
            'days_until' => $phase === 'upcoming' ? (int) $today->diffInDays($start) : null,
            'phase' => $phase,
            'status' => $i->status,
            'status_label' => self::INVOICE_STATUS[$i->status] ?? $i->status,
            'kind' => $i->offer_id ? 'offer' : 'custom',
            'offer' => null,
            'travellers' => [],
            'pax' => null,
            'hotels' => [],
            'services' => [],
        ] + ($can['invoices'] ? ['invoice_number' => $i->invoice_number, 'invoice_url' => '/invoices?highlight='.$i->id] : []);

        if ($i->offer_id) {
            $snap = (array) $i->offer_lines;
            $t['offer'] = [
                'title' => $snap['offer_title'] ?? $offer?->title,
                'destination' => $offer?->destination,
                'travel_mode' => $offer?->travel_mode ? (Offer::TRAVEL_MODES[$offer->travel_mode] ?? null) : null,
            ] + ($can['offers'] && $offer ? ['url' => '/offers/'.$offer->id] : []);
            $lines = collect($snap['lines'] ?? []);
            $t['pax'] = (int) ($i->offer_seats ?: $lines->sum('pax'));
            $t['travellers'] = $lines->map(fn ($l) => [
                'pax' => (int) ($l['pax'] ?? 0),
                'room' => isset($l['room']) ? (OfferOption::ROOM_TYPES[$l['room']] ?? null) : null,
                'choice' => collect($l['labels'] ?? [])->pluck('option')->filter()->implode(' · '),
            ])->values()->all();
            $t['hotels'] = $offer ? self::hotels($offer, $lines) : [];
        } else {
            // فاتورة يدوية: البنود كما وصفها البائع (البيان وحده، لا وكيل ولا تكلفة)
            $t['services'] = $i->items()->orderBy('sort_order')->limit(12)->pluck('description')
                ->map(fn ($d) => trim((string) $d))->filter()->unique()->values()->all();
        }

        return $t;
    }

    /** فنادق ما اختاره العميل: المدينة والاسم والتصنيف والوجبات والمسافة والليالي، بلا تكرار بين السطور */
    private static function hotels(Offer $offer, Collection $lines): array
    {
        $offer->loadMissing(['groups.options.stays']);
        $seen = [];
        $out = [];
        foreach ($lines as $l) {
            foreach ((array) ($l['sel'] ?? []) as $groupId => $optionId) {
                $g = $offer->groups->firstWhere('id', (int) $groupId);
                $opt = $g?->options->firstWhere('id', (int) $optionId);
                if (!$g || !$opt || !$g->isStay()) {
                    continue;
                }
                foreach ($opt->stays as $s) {
                    if (isset($seen[$s->id])) {
                        continue;
                    }
                    $seen[$s->id] = true;
                    $out[] = [
                        'city' => OfferPricing::stayCity($offer, $g, $s),
                        'name' => $s->name,
                        'rating' => $s->rating ? $s->rating.($s->rating_plus ? '+' : '') : null,
                        'meals' => $s->meals ?: null,
                        'distance' => $s->distance_haram ?: null,
                        'location' => $s->location ?: null,
                        'nights' => OfferPricing::stayNights($offer, $g, $s) ?: null,
                    ];
                }
            }
        }

        return $out;
    }

    private static function quotes(WaConversation $c, User $u, array $can): array
    {
        return QuoteRequest::query()->visibleTo($u)->with('offer:id,title')
            ->where('quote_requests.conversation_id', $c->id)
            ->orderByDesc('quote_requests.id')->limit(10)->get()
            ->map(fn (QuoteRequest $q) => [
                'number' => $q->request_number,
                'status' => $q->status,
                'status_label' => QuoteRequest::STATUS_LABELS[$q->status] ?? $q->status,
                'open' => in_array($q->status, ['new', 'priced', 'sent'], true),
                'date' => $q->created_at?->toDateString(),
                'offer' => $q->offer?->title,
                'pax' => $q->pax() ?: null,
                'depart_date' => $q->depart_date?->toDateString(),
            ] + ($can['quotes'] ? ['url' => '/quote-requests?status=all&search='.rawurlencode((string) $q->request_number)] : []))
            ->values()->all();
    }

    /** ملاحظات البوت والنظام عن العميل — هي نفسها ما يقرؤه البوت في سياقه */
    private static function notes(WaConversation $c): array
    {
        return $c->notes()->orderByDesc('id')->limit(20)->get(['note', 'source', 'created_at'])
            ->map(fn ($n) => ['text' => $n->note, 'source' => $n->source, 'at' => $n->created_at?->format('Y-m-d H:i')])->all();
    }

    private static function conversation(WaConversation $c): array
    {
        $counts = $c->messages()->selectRaw('direction, count(*) as n')->groupBy('direction')->pluck('n', 'direction');

        return [
            'started_at' => $c->created_at?->format('Y-m-d'),
            'last_inbound_at' => $c->last_inbound_at?->format('Y-m-d H:i'),
            'messages_in' => (int) ($counts['in'] ?? 0),
            'messages_out' => (int) ($counts['out'] ?? 0),
            'profile_name' => $c->wa_profile_name,
            'username' => $c->wa_username,
        ];
    }
}
