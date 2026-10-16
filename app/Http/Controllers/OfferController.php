<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Disbursement;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\OfferCost;
use App\Models\OfferGroup;
use App\Models\OfferOption;
use App\Models\Service;
use App\Services\OfferCardService;
use App\Services\OfferPricing;
use App\Services\OfferSettlementService;
use App\Services\OfferWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * العروض والباقات والعروض الجاهزة — صفحة تحرير واحدة لسجل واحد.
 * «العروض الجاهزة» تصفية لجدول العروض نفسه، لا جدول ولا نموذج ثانٍ.
 */
class OfferController extends Controller
{
    private function canCost(): bool
    {
        return auth()->user()->can('offers.view_cost');
    }

    // ==================== القوائم ====================

    public function index(Request $request)
    {
        abort_unless(auth()->user()->can('offers.view'), 403);

        $offers = Offer::with(['groups.options.stays', 'options.stays'])
            ->when($request->search, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->when($request->kind === 'ready', fn ($q) => $q->where('is_ready', true))
            ->when($request->kind === 'open', fn ($q) => $q->where('is_ready', false))
            ->orderBy('sort_order')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        $offers->getCollection()->transform(fn ($o) => $this->rowFor($o));

        return Inertia::render('Offers/Index', [
            'title' => 'العروض والباقات',
            'offers' => $offers,
            'filters' => $request->only(['search', 'category', 'kind']),
            'categories' => Offer::CATEGORIES,
            'can' => $this->can(),
        ]);
    }

    /** العروض الجاهزة: رحلات بتاريخ ومقاعد — تصفية للجدول نفسه */
    public function ready(Request $request)
    {
        abort_unless(auth()->user()->can('offers.view'), 403);

        $filter = $request->get('filter', 'upcoming');
        $today = now()->toDateString();

        $offers = Offer::ready()->with(['groups.options.stays', 'options.stays'])
            ->when($filter === 'upcoming', fn ($q) => $q->whereDate('trip_date', '>', $today)
                ->where(fn ($w) => $w->whereNull('settlement_status')->orWhere('settlement_status', '!=', 'approved')))
            ->when($filter === 'past', fn ($q) => $q->where(fn ($w) => $w->whereDate('trip_date', '<=', $today)->orWhere('settlement_status', 'approved')))
            ->orderBy('trip_date')->orderBy('id')
            ->get()
            ->map(fn ($o) => $this->rowFor($o));

        return Inertia::render('Offers/Ready', [
            'title' => 'العروض الجاهزة',
            'offers' => $offers,
            'filter' => $filter,
            'can' => $this->can(),
        ]);
    }

    private function can(): array
    {
        $u = auth()->user();

        return [
            'create' => $u->can('offers.create'),
            'update' => $u->can('offers.update'),
            'delete' => $u->can('offers.delete'),
            'cost' => $u->can('offers.view_cost'),
            'invoice' => $u->can('invoices.create'),
        ];
    }

    /** صفّ العرض في القوائم */
    private function rowFor(Offer $o): array
    {
        $booked = $o->bookedSeats();
        $approved = (int) Invoice::where('offer_id', $o->id)->where('status', 'approved')->sum('offer_seats');
        $checks = $o->forBotChecks();
        $fail = collect($checks)->firstWhere('ok', false);
        $issues = OfferPricing::issues($o);
        $state = $this->readyState($o, $booked, $issues);

        return [
            'id' => $o->id,
            'title' => $o->title,
            'category' => $o->category,
            'category_label' => $o->categoryLabel(),
            'is_ready' => (bool) $o->is_ready,
            'trip_date' => $o->trip_date?->toDateString(),
            'return_date' => $o->return_date?->toDateString(),
            'travel_mode' => $o->travel_mode,
            'travel_mode_label' => Offer::TRAVEL_MODES[$o->travel_mode] ?? null,
            'destination' => $o->destination,
            'total_seats' => $o->total_seats,
            'booked' => $booked,
            'approved_seats' => $approved,
            'available' => $o->total_seats ? $o->total_seats - $booked : null,
            'state' => $state,
            'price_from' => $o->priceFrom(),
            'is_active' => (bool) $o->is_active,
            'is_bot_visible' => (bool) $o->is_bot_visible,
            'for_bot' => !$fail,
            'bot_blocker' => $fail['label'] ?? null,
            'issues_count' => count($issues),
            'invoices_count' => Invoice::where('offer_id', $o->id)->where('status', '!=', 'rejected')->count(),
            'is_closed' => $o->isClosed(),
            'valid_from' => $o->valid_from?->toDateString(),
            'valid_to' => $o->valid_to?->toDateString(),
            'card' => $o->cardImage() ? ($o->cardIsStale() ? 'stale' : 'fresh') : 'none',
        ];
    }

    private function readyState(Offer $o, int $booked, array $issues): array
    {
        $today = now()->toDateString();
        if ($o->isClosed()) return ['label' => 'مُقفل', 'tone' => 'gray'];
        if (!$o->is_ready) return ['label' => $o->is_active ? 'تاريخ مفتوح' : 'معطّل', 'tone' => $o->is_active ? 'blue' : 'gray'];
        $end = $o->return_date ?: $o->trip_date;
        if ($end && $end->toDateString() < $today) return ['label' => 'عادت — بانتظار الإقفال', 'tone' => 'blue'];
        if ($o->trip_date && $o->trip_date->toDateString() <= $today) return ['label' => 'في الرحلة', 'tone' => 'blue'];
        if ($issues) return ['label' => 'لا يُباع: بيانات البيع ناقصة', 'tone' => 'amber'];
        $label = $o->availabilityLabel();
        if ($label === 'مكتمل') return ['label' => 'مكتمل', 'tone' => 'red'];
        if ($label === 'مقاعد محدودة') return ['label' => 'مقاعد محدودة', 'tone' => 'amber'];

        return ['label' => 'متاح للحجز', 'tone' => 'green'];
    }

    // ==================== صفحة العرض ====================

    public function create(Request $request)
    {
        abort_unless(auth()->user()->can('offers.create'), 403);

        return $this->page(null, $request);
    }

    public function show(Request $request, Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.view'), 403);

        return $this->page($offer, $request);
    }

    private function page(?Offer $offer, Request $request)
    {
        $canCost = $this->canCost();
        $props = [
            'title' => $offer ? $offer->title : ($request->boolean('ready') ? 'عرض جاهز جديد' : 'عرض جديد'),
            'offer' => $offer ? $this->editPayload($offer) : $this->blankPayload($request->boolean('ready')),
            'tab' => $request->get('tab', 'data'),
            'from' => $request->get('from', $offer?->is_ready || $request->boolean('ready') ? 'ready' : 'offers'),
            'meta' => [
                'categories' => Offer::CATEGORIES,
                'routeModes' => Offer::ROUTE_MODES,
                'routeCities' => Offer::ROUTE_CITIES,
                'roomTypes' => OfferOption::ROOM_TYPES,
                'travelModes' => Offer::TRAVEL_MODES,
                'pricingModes' => Offer::PRICING_MODES,
                'groupKinds' => OfferGroup::KINDS,
                'rate' => OfferPricing::rate(),
            ],
            // للإقفال وحده: المصاريف المشتركة بوكيلها. العرض لا يحمل وكيلاً ولا تكلفة
            'agents' => $canCost ? Agent::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'currency']) : [],
            'can' => $this->can(),
        ];

        if ($offer) {
            $offer->load(['groups.options.stays', 'options.stays']);
            $props += [
                'summary' => $this->rowFor($offer),
                'issues' => OfferPricing::issues($offer),
                'sold' => array_map(fn ($m) => array_keys($m), OfferWriter::soldRefs($offer)),
                'bot' => $this->botData($offer),
                'sales' => $this->salesData($offer, $canCost),
                'closing' => $canCost ? $this->closingData($offer) : null,
                // تبويب «📤 المشاركة» (E38): رموز QR وبطاقة الحالة، كلٌّ بعلمه
                'shareEnabled' => \App\Services\WhatsApp\OfferQrService::available()
                    && (\App\Support\FeatureFlags::on('wa_qr_links') || \App\Support\FeatureFlags::on('offer_story_card')),
            ];
        }

        return Inertia::render('Offers/Show', $props);
    }

    private function blankPayload(bool $ready): array
    {
        $today = now();

        return [
            'id' => null,
            'title' => '', 'category' => 'umrah', 'route_mode' => 'makkah_madinah',
            'description_client' => '', 'notes_public' => '', 'includes' => [], 'excludes' => [],
            'valid_from' => $today->toDateString(), 'valid_to' => null, 'nights' => null, 'airline' => '',
            'sort_order' => 0, 'is_active' => true, 'is_bot_visible' => false,
            'requirements' => '', 'visa_validity' => '', 'visa_entries' => '', 'visa_processing' => '', 'price_per_person' => null,
            'is_ready' => $ready,
            'trip_date' => $ready ? $today->copy()->addDays(21)->toDateString() : null,
            'return_date' => $ready ? $today->copy()->addDays(30)->toDateString() : null,
            'travel_mode' => 'land', 'destination' => $ready ? 'مكة والمدينة' : '', 'total_seats' => $ready ? 40 : null,
            'pricing_mode' => 'city', 'base_sell_pp' => 0,
            'groups' => [
                ['id' => null, 'kind' => 'stay', 'label' => 'فندق مكة', 'city' => 'مكة', 'nights' => 5, 'options' => []],
                ['id' => null, 'kind' => 'stay', 'label' => 'فندق المدينة', 'city' => 'المدينة', 'nights' => 4, 'options' => []],
            ],
        ];
    }

    /** بنية العرض للتحرير — بيانات البيع وحدها؛ الوكيل والتكلفة يُدخلان في الفاتورة */
    private function editPayload(Offer $o): array
    {
        $o->loadMissing(['groups.options.stays']);

        return [
            'id' => $o->id,
            'title' => $o->title, 'category' => $o->category, 'route_mode' => $o->route_mode,
            'description_client' => $o->description_client, 'notes_public' => $o->notes_public,
            'includes' => $o->includes ?: [], 'excludes' => $o->excludes ?: [],
            'valid_from' => $o->valid_from?->toDateString(), 'valid_to' => $o->valid_to?->toDateString(),
            'nights' => $o->nights, 'airline' => $o->airline, 'sort_order' => $o->sort_order,
            'is_active' => (bool) $o->is_active, 'is_bot_visible' => (bool) $o->is_bot_visible,
            'requirements' => $o->requirements, 'visa_validity' => $o->visa_validity, 'visa_entries' => $o->visa_entries,
            'visa_processing' => $o->visa_processing, 'price_per_person' => $o->price_per_person,
            'is_ready' => (bool) $o->is_ready, 'trip_date' => $o->trip_date?->toDateString(), 'return_date' => $o->return_date?->toDateString(),
            'travel_mode' => $o->travel_mode ?: 'land', 'destination' => $o->destination, 'total_seats' => $o->total_seats,
            'pricing_mode' => $o->pricing_mode ?: 'bundle', 'base_sell_pp' => (float) $o->base_sell_pp,
            'groups' => $o->groups->map(fn ($g) => [
                'id' => $g->id, 'kind' => $g->kind, 'label' => $g->label, 'city' => $g->city, 'nights' => $g->nights,
                'options' => $g->options->map(fn ($opt) => [
                    'id' => $opt->id, 'label' => $opt->label, 'includes_note' => $opt->includes_note,
                    'prices' => array_merge(array_fill_keys(array_keys(OfferOption::ROOM_TYPES), null), $opt->prices ?: []),
                    'sell_flat' => (float) $opt->sell_flat, 'capacity' => $opt->capacity, 'is_active' => (bool) $opt->is_active,
                    'stays' => $opt->stays->map(fn ($s) => [
                        'id' => $s->id, 'city' => $s->city, 'name' => $s->name, 'rating' => $s->rating,
                        'rating_plus' => (bool) $s->rating_plus, 'location' => $s->location, 'meals' => $s->meals,
                        'distance_haram' => $s->distance_haram, 'nights' => $s->nights,
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** تبويب «ما يراه البوت»: الحمولة الحية وشروط الظهور والبطاقة */
    private function botData(Offer $o): array
    {
        $payload = $o->toBotArray();
        $leaks = [];
        $scan = function ($v, $path) use (&$scan, &$leaks) {
            if (is_array($v)) {
                foreach ($v as $k => $x) {
                    if (is_string($k) && preg_match('/agent|cost|rate|margin|seats|profit|capacity/i', $k)) {
                        $leaks[] = $path . '.' . $k;
                    }
                    $scan($x, $path . '.' . $k);
                }
            }
        };
        $scan($payload, '');

        $card = $o->cardImage();
        $stamp = $o->card_generated_at?->timestamp ?: $o->updated_at?->timestamp;

        return [
            'payload' => $payload,
            'checks' => $o->forBotChecks(),
            'for_bot' => Offer::forBot()->whereKey($o->id)->exists(),
            'leaks' => $leaks,
            'card_url' => $card ? Storage::disk('public')->url($card) . '?v=' . $stamp : null,
            'card_is_custom' => (bool) $o->custom_card_path,
            'card_is_stale' => $o->cardIsStale(),
            'hero_url' => $o->hero_path ? Storage::disk('public')->url($o->hero_path) : null,
        ];
    }

    /** تبويب «الفواتير والمقاعد»: الفواتير المرتبطة، والمقاعد، والربح، وما ندين به لكل وكيل */
    private function salesData(Offer $o, bool $canCost): array
    {
        $invoices = Invoice::with(['client:id,name,code,phone', 'seller.user:id,name'])
            ->where('offer_id', $o->id)->orderByDesc('id')->get();

        $approved = $invoices->where('status', 'approved');
        $pending = $invoices->whereIn('status', ['pending', 'editing']);
        $data = [
            'seats' => [
                'total' => $o->total_seats,
                'approved' => (int) $approved->sum('offer_seats'),
                'pending' => (int) $pending->sum('offer_seats'),
                'available' => $o->availableSeats(),
                'label' => $o->availabilityLabel(),
            ],
            'invoices' => $invoices->map(fn ($i) => [
                'id' => $i->id,
                'number' => $i->invoice_number,
                'client' => $i->client?->name,
                'phone' => $i->client_phone ?: $i->client?->phone,
                'seller' => $i->seller?->user?->name,
                'status' => $i->status,
                'seats' => (int) $i->offer_seats,
                'lines' => collect($i->offer_lines['lines'] ?? [])->map(fn ($l) => [
                    'text' => collect($l['labels'] ?? [])->pluck('option')->implode(' · '),
                    'room' => isset($l['room']) ? (OfferOption::ROOM_TYPES[$l['room']] ?? null) : null,
                    'pax' => $l['pax'] ?? 0,
                ])->all(),
                'sell' => (float) $i->total_sell_jod,
                'profit' => $canCost ? (float) $i->profit_jod : null,
                // بنود بلا وكيل أو تكلفة — تمنع الاعتماد
                'missing' => in_array($i->status, ['pending', 'editing'], true) ? (int) ($i->offer_lines['missing'] ?? 0) : 0,
                'date' => $i->created_at?->toDateString(),
            ])->values()->all(),
        ];

        if ($canCost) {
            $sales = round((float) $approved->sum('total_sell_jod'), 3);
            $cost = round((float) $approved->sum('total_cost_jod'), 3);
            $settlement = $o->settlement;
            // ربح العرض = مجموع أرباح فواتيره. مصاريف الإقفال المشتركة مصروف مستقل لا يُخصم منه
            $data['pl'] = [
                'pax' => (int) $approved->sum('offer_seats'),
                'sales' => $sales,
                'cost' => $cost,
                'profit' => round($sales - $cost, 3),
                'shared' => round((float) ($settlement->total_jod ?? 0), 3),
                'settled' => (bool) $settlement,
            ];
            $data['actual'] = $this->actualCosts($approved);

            // ما ندين به لكل وكيل عن العرض
            $items = DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoices.offer_id', $o->id)->where('invoices.status', 'approved')->whereNull('invoices.deleted_at')
                ->groupBy('invoice_items.agent_id')->selectRaw('invoice_items.agent_id, sum(invoice_items.total_cost_jod) as posted')
                ->pluck('posted', 'agent_id');
            $perAgentSettl = collect($settlement->lines ?? [])->filter(fn ($r) => !empty($r['agent_id']) && !empty($r['diff']))
                ->groupBy('agent_id')->map(fn ($g) => round($g->sum('diff'), 3));
            $paid = Disbursement::where('offer_id', $o->id)->where('status', 'approved')->whereNotNull('agent_id')->get()
                ->groupBy('agent_id')->map(fn ($g) => round($g->sum(fn ($d) => $d->currency === 'SAR' ? OfferPricing::toJod((float) $d->amount, 'SAR') : (float) $d->amount), 3));
            $agentIds = collect($items->keys())->concat($perAgentSettl->keys())->concat($paid->keys())->filter()->unique();
            $names = Agent::whereIn('id', $agentIds)->pluck('name', 'id');
            $data['agents'] = $agentIds->map(fn ($a) => [
                'agent_id' => (int) $a,
                'agent' => $names[$a] ?? '—',
                'posted' => round((float) ($items[$a] ?? 0), 3),
                'settlement' => (float) ($perAgentSettl[$a] ?? 0),
                'paid' => (float) ($paid[$a] ?? 0),
                'remaining' => round((float) ($items[$a] ?? 0) + (float) ($perAgentSettl[$a] ?? 0) - (float) ($paid[$a] ?? 0), 3),
            ])->values()->all();
        }

        return $data;
    }

    /** التكلفة الفعلية لكل فندق وسعة من الفواتير المعتمدة: ما دُفع فعلاً، وبأي وكيل */
    private function actualCosts($approved): array
    {
        $m = [];
        foreach ($approved as $inv) {
            foreach (($inv->offer_lines['costs'] ?? []) as $r) {
                if (($r['kind'] ?? null) !== 'hotel' || empty($r['done'])) {
                    continue;
                }
                $k = $r['src'] . '|' . ($r['currency'] ?? 'SAR');
                $m[$k] ??= ['label' => preg_replace('/ · [^·]*$/u', '', $r['description']), 'currency' => $r['currency'] ?? 'SAR', 'pax' => 0, 'sum' => 0.0, 'min' => null, 'max' => null, 'agents' => []];
                $pn = (float) $r['unit'];
                $m[$k]['pax'] += (int) $r['qty'];
                $m[$k]['sum'] += $pn * (int) $r['qty'];
                $m[$k]['min'] = $m[$k]['min'] === null ? $pn : min($m[$k]['min'], $pn);
                $m[$k]['max'] = $m[$k]['max'] === null ? $pn : max($m[$k]['max'], $pn);
                $m[$k]['agents'][(int) $r['agent_id']] = true;
            }
        }
        $names = Agent::whereIn('id', collect($m)->flatMap(fn ($x) => array_keys($x['agents']))->unique())->pluck('name', 'id');

        return array_values(array_map(fn ($x) => [
            'label' => $x['label'],
            'currency' => $x['currency'],
            'pax' => $x['pax'],
            'avg' => $x['pax'] ? round($x['sum'] / $x['pax'], 2) : 0,
            'min' => $x['min'],
            'max' => $x['max'],
            'agents' => collect(array_keys($x['agents']))->map(fn ($a) => $names[$a] ?? '—')->values()->all(),
        ], $m));
    }

    private function closingData(Offer $o): array
    {
        $settlement = $o->settlement;
        if ($settlement) {
            return [
                'settled' => true,
                'total' => $settlement->total_jod,
                'approved_at' => $settlement->approved_at?->format('Y-m-d H:i'),
                'entry' => $settlement->journal_entry_id ? \App\Models\JournalEntry::find($settlement->journal_entry_id)?->entry_number : null,
                'rows' => $settlement->lines,
            ];
        }

        return ['settled' => false] + OfferSettlementService::preview($o, []);
    }

    // ==================== الحفظ ====================

    public function store(Request $request)
    {
        abort_unless(auth()->user()->can('offers.create'), 403);

        $data = $request->validate(OfferWriter::rules($request->all()), OfferWriter::messages());
        $offer = OfferWriter::save(null, $data);
        \App\Models\AuditLog::log(\App\Enums\AuditAction::Create, 'offer', $offer->id, $offer->title);

        return redirect()->route('offers.show', ['offer' => $offer->id, 'tab' => $request->get('tab', 'data')])
            ->with('success', 'تم إضافة العرض');
    }

    public function update(Request $request, Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.update'), 403);
        if ($offer->isClosed()) {
            return back()->with('error', 'العرض مُقفل ولا يُعدَّل.');
        }

        $data = $request->validate(OfferWriter::rules($request->all()), OfferWriter::messages());
        $before = self::priceSnapshot($offer);
        OfferWriter::save($offer, $data);

        // الأسعار التي يقرؤها البوت ويرسلها للعملاء: أي تغيير فيها صف تحذير بالقيم قبل وبعد
        $after = self::priceSnapshot($offer);
        if ($before !== $after) {
            $changed = [];
            foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
                if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                    $changed[$key] = ['before' => $before[$key] ?? null, 'after' => $after[$key] ?? null];
                }
            }
            \App\Models\AuditLog::safe(\App\Enums\AuditAction::OfferPriceChange, 'offer', $offer->id, $offer->title, ['changed' => $changed]);
        }
        \App\Models\AuditLog::log(\App\Enums\AuditAction::Update, 'offer', $offer->id, $offer->title);

        return back()->with('success', 'تم حفظ العرض. البوت والبطاقة والفواتير الجديدة تقرأ النسخة المحفوظة الآن.');
    }

    public function destroy(Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.delete'), 403);

        if (Invoice::withTrashed()->where('offer_id', $offer->id)->exists()) {
            return back()->with('error', 'للعرض فواتير مرتبطة ولا يُحذف. عطّله أو أخفه عن البوت بدلاً من ذلك.');
        }

        OfferCardService::forget($offer);

        DB::transaction(function () use ($offer) {
            $optionIds = $offer->options()->pluck('id');
            \App\Models\OfferStay::whereIn('option_id', $optionIds)->delete();
            OfferCost::where('offer_id', $offer->id)->delete();
            $offer->options()->delete();
            OfferGroup::where('offer_id', $offer->id)->delete();
            $offer->delete();
            \App\Models\AuditLog::log(\App\Enums\AuditAction::Delete, 'offer', $offer->id, $offer->title, null, \App\Enums\AuditSeverity::Warn);
        });

        return redirect()->route('offers.index')->with('success', 'تم حذف العرض');
    }

    /** سعر «يبدأ من» وأسعار كل خيار بيع لكل سعة غرفة: ما يقرؤه البوت ويرسله */
    private static function priceSnapshot(Offer $offer): array
    {
        $offer = $offer->fresh() ?? $offer;
        $snapshot = ['يبدأ من' => $offer->priceFrom()];
        foreach ($offer->sellOptions() as $option) {
            $snapshot[$option->label ?: $option->ref] = $option->prices;
        }

        return $snapshot;
    }

    /** إظهار/إخفاء للبوت — عبر Query Builder فلا تصير البطاقة «قديمة» */
    public function toggleBot(Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.update'), 403);

        if (!$offer->is_bot_visible && !$offer->priceFrom()) {
            $msg = $offer->isVisa()
                ? 'أدخل سعر التأشيرة قبل إظهار العرض للبوت.'
                : 'أضف خياراً واحداً على الأقل بسعر قبل إظهار العرض للبوت.';

            return back()->with('error', $msg);
        }

        DB::table('offers')->where('id', $offer->id)->update(['is_bot_visible' => !$offer->is_bot_visible]);
        // ظاهر للبوت = يعرضه على العملاء (Offer::toBotArray)
        \App\Models\AuditLog::safe(\App\Enums\AuditAction::OfferBotVisibility, 'offer', $offer->id, $offer->title, ['visible' => !$offer->is_bot_visible]);

        return back()->with('success', !$offer->is_bot_visible ? 'العرض ظاهر للبوت الآن' : 'أُخفي العرض عن البوت');
    }

    // ==================== الإقفال ====================

    public function settlementPreview(Request $request, Offer $offer)
    {
        abort_unless($this->canCost(), 403);

        $request->validate($this->settleRules());

        return response()->json(OfferSettlementService::preview($offer, $request->only(['shared', 'bill'])));
    }

    private function settleRules(): array
    {
        return [
            'shared' => 'nullable|array|max:30',
            'shared.*.agent_id' => 'nullable|integer|exists:agents,id',
            'shared.*.description' => 'nullable|string|max:150',
            'shared.*.amount' => 'nullable|numeric|min:0|max:9999999',
            'shared.*.currency' => 'nullable|in:SAR,JOD',
            'bill' => 'nullable|array',
            'bill.*' => 'nullable|numeric|min:-999999|max:999999',
        ];
    }

    public function settle(Request $request, Offer $offer)
    {
        abort_unless($this->canCost() && auth()->user()->can('offers.update'), 403);

        $request->validate($this->settleRules());

        try {
            OfferSettlementService::approve($offer, $request->only(['shared', 'bill']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \App\Services\AccountingService::notifyFailure("إقفال العرض {$offer->title}", $e);

            return back()->with('error', 'تعذّر اعتماد التسوية ولم يُحفظ أي تغيير: ' . $e->getMessage());
        }

        return redirect()->route('offers.show', ['offer' => $offer->id, 'tab' => 'close'])
            ->with('success', 'اعتُمد الإقفال وأُقفل العرض: المصاريف المشتركة سُجّلت مصروفاً، وربح العرض بقي كما هو. اختفى العرض من فاتورة العرض ومن البوت.');
    }

    // ==================== بطاقة العرض ====================

    public function generateCard(Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.update'), 403);

        try {
            OfferCardService::generate($offer);
        } catch (\Throwable $e) {
            return back()->with('error', 'تعذّر توليد البطاقة: ' . $e->getMessage());
        }

        return back()->with('success', 'تم توليد بطاقة العرض');
    }

    public function previewCard(Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.update'), 403);

        return response(OfferCardService::html($offer))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function uploadCard(Request $request, Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.update'), 403);

        $request->validate([
            'card' => 'required|image|mimes:png,jpg,jpeg|max:5120',
        ], ['card.max' => 'أقصى حجم للصورة 5 ميغابايت (حدّ واتساب).']);

        $old = $offer->custom_card_path;
        $path = $request->file('card')->store('offer-cards', 'public');
        $offer->forceFill(['custom_card_path' => $path])->saveQuietly();

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return back()->with('success', 'تم رفع البطاقة — ستُستخدم بدل المولّدة');
    }

    public function deleteCard(Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.update'), 403);

        if ($offer->custom_card_path) {
            Storage::disk('public')->delete($offer->custom_card_path);
            $offer->forceFill(['custom_card_path' => null])->saveQuietly();
        }

        return back()->with('success', 'أُزيلت البطاقة المرفوعة');
    }

    public function uploadHero(Request $request, Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.update'), 403);

        $request->validate(['hero' => 'required|image|mimes:png,jpg,jpeg,webp|max:5120']);

        $old = $offer->hero_path;
        $path = $request->file('hero')->store('offer-heroes', 'public');
        $offer->forceFill(['hero_path' => $path])->saveQuietly();

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return back()->with('success', 'تم رفع صورة الترويسة — أعد توليد البطاقة لتظهر');
    }
}
