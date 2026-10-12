<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Agent;
use App\Models\Client;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\QuoteRequest;
use App\Models\Service;
use App\Models\AuditLog;
use App\Services\NumberingService;
use App\Services\BalanceService;
use App\Services\AccountingService;
use App\Services\OfferPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    private static function rate(): float
    {
        return OfferPricing::rate();
    }

    public function index(Request $request)
    {
        $invoices = Invoice::query()
            ->with(['client:id,name,code', 'creator:id,name', 'approver:id,name', 'offer:id,title,is_ready'])
            ->when($request->search, fn ($q, $s) => $q->where('invoice_number', 'like', "%{$s}%")
                ->orWhereHas('client', fn ($q2) => $q2->where('name', 'like', "%{$s}%")))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->offer, fn ($q, $o) => $q->where('offer_id', $o))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Invoices/Index', [
            'title' => 'المطالبات',
            'invoices' => $invoices,
            'filters' => $request->only(['search', 'status', 'offer']),
            'agents' => Agent::where('is_active', true)->select('id', 'name', 'code', 'currency')->get(),
            'clients' => Client::where('is_active', true)->select('id', 'name', 'code', 'phone')->get(),
            'services' => Service::where('is_active', true)->get(),
            'employees' => \App\Models\Employee::with('user:id,name')->where('is_active', true)->get()
                ->map(fn ($e) => ['id' => $e->id, 'name' => $e->user?->name ?? ('موظف #' . $e->id)])
                ->values(),
            'currentEmployeeId' => auth()->user()->employee?->id,
            'exchangeRate' => self::rate(),
            // فاتورة العرض: العروض القابلة للبيع بقوائمها المنسدلة (جانب البيع فقط)
            'offerCatalog' => $this->offerCatalog(),
            'roomTypes' => OfferOption::ROOM_TYPES,
            'today' => now()->toDateString(),
            'prefill' => $request->quote ? $this->quotePrefill((int) $request->quote) : null,
            'openOffer' => $request->integer('offer_new') ?: null,
            'offerMode' => $request->boolean('offer_mode'),
        ]);
    }

    /** العروض التي يمكن أن تظهر في فاتورة العرض — الجاهزة القادمة والمفتوحة الفعّالة */
    private function offerCatalog(): array
    {
        $today = now()->toDateString();

        return Offer::with(['groups.options.stays', 'groups.options.costs', 'fixedCosts'])
            ->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('settlement_status')->orWhere('settlement_status', '!=', 'approved'))
            ->where(fn ($w) => $w->where('is_ready', false)->orWhereDate('trip_date', '>', $today))
            ->orderBy('trip_date')->orderBy('sort_order')->orderByDesc('id')
            ->get()
            ->map(fn ($o) => self::posOffer($o))
            ->values()->all();
    }

    /** بنية العرض في نموذج الفاتورة: القوائم المنسدلة وأسعار البيع والمتاح — بلا تكاليف */
    public static function posOffer(Offer $o, ?int $ignoreInvoiceId = null): array
    {
        $o->loadMissing(['groups.options.stays', 'groups.options.costs', 'fixedCosts']);
        $issues = OfferPricing::issues($o);
        $today = now()->toDateString();
        $available = $o->availableSeats($ignoreInvoiceId);

        $block = null;
        if ($o->isClosed()) $block = 'مُقفل';
        elseif (!$o->is_active) $block = 'غير فعّال';
        elseif ($o->is_ready && $o->trip_date && $o->trip_date->toDateString() <= $today) $block = 'أقلعت الرحلة';
        elseif ($o->is_ready && $available !== null && $available <= 0) $block = 'مكتمل';
        elseif ($issues) $block = 'التكاليف ناقصة';

        return [
            'id' => $o->id,
            'title' => $o->title,
            'category_label' => $o->categoryLabel(),
            'is_ready' => (bool) $o->is_ready,
            'trip_date' => $o->trip_date?->toDateString(),
            'return_date' => $o->return_date?->toDateString(),
            'travel_mode' => $o->travel_mode,
            'travel_mode_label' => Offer::TRAVEL_MODES[$o->travel_mode] ?? null,
            'destination' => $o->destination,
            'total_seats' => $o->total_seats,
            'available' => $available,
            'valid_from' => $o->valid_from?->toDateString(),
            'valid_to' => $o->valid_to?->toDateString(),
            'price_from' => $o->priceFrom(),
            'block' => $block,
            'has_stay' => $o->groups->contains(fn ($g) => $g->isStay()),
            'groups' => $o->groups->map(fn ($g) => [
                'id' => $g->id,
                'label' => $g->label,
                'kind' => $g->kind,
                'options' => $g->options->map(fn ($opt) => [
                    'id' => $opt->id,
                    'label' => OfferPricing::optionLabel($o, $g, $opt),
                    'is_active' => (bool) $opt->is_active,
                    'prices' => $g->isStay() ? collect(OfferOption::ROOM_TYPES)->keys()
                        ->mapWithKeys(fn ($r) => [$r => $opt->sellFor($r)])->all() : null,
                    'rooms' => $g->isStay() ? array_values(array_filter(OfferPricing::ROOMS, fn ($r) => $opt->sellFor($r) !== null
                        && !$opt->stays->contains(fn ($s) => $s->rateFor($r) === null))) : null,
                    'sell_flat' => (float) $opt->sell_flat,
                    'capacity' => $opt->capacity,
                    'used' => $opt->capacity ? OfferPricing::optionUsage($o, $opt->id, $ignoreInvoiceId) : null,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** تحويل طلب حجز من البوت إلى فاتورة عرض مملوءة */
    private function quotePrefill(int $id): ?array
    {
        $q = QuoteRequest::with('offer.groups.options')->find($id);
        if (!$q || !$q->offer) {
            return null;
        }
        $o = $q->offer;
        $sel = $q->offer_selection['sel'] ?? null;
        $valid = is_array($sel) && $o->groups->every(fn ($g) => $g->options->contains(fn ($opt) => $opt->id === (int) ($sel[$g->id] ?? 0) && $opt->is_active));

        return [
            'quote_id' => $q->id,
            'number' => $q->request_number,
            'client_name' => $q->customer_name ?: $q->client?->name ?: '',
            'client_phone' => $q->phone,
            'offer_id' => $o->id,
            'trip_date' => $o->is_ready ? $o->trip_date?->toDateString() : ($q->depart_date?->toDateString() ?: now()->addWeek()->toDateString()),
            'lines' => [[
                'sel' => $valid ? array_map('intval', $sel) : null,
                'room' => $valid ? ($q->offer_selection['room'] ?? null) : null,
                'pax' => max(1, (int) ($q->offer_selection['pax'] ?? $q->pax())),
            ]],
            'note' => $valid ? null : ($sel
                ? 'بعض خيارات الطلب لم تعد فعّالة، فاختيرت الافتراضية. راجعها مع العميل.'
                : "الطلب نصّ حر: «{$q->details}». اختر الفنادق والسعة."),
            'offer' => self::posOffer($o),
        ];
    }

    /** تفاصيل فاتورة للتعديل — ومعها بنية العرض إن كانت فاتورة عرض */
    public function details(Invoice $invoice)
    {
        $invoice->load(['items.agent:id,name,code', 'client:id,name,code,phone']);
        $out = $invoice->toArray();
        if ($invoice->offer_id && $invoice->offer) {
            $out['offer_form'] = self::posOffer($invoice->offer, $invoice->id);
        }

        return response()->json($out);
    }

    public function print(Invoice $invoice)
    {
        $invoice->load(['client:id,name,code', 'items.agent:id,name,code', 'creator:id,name', 'approver:id,name', 'offer:id,title,trip_date,return_date,travel_mode,destination']);

        $template = \App\Models\Setting::where('key', 'print_template_financial')->first();
        $templateUrl = $template?->value ? \Storage::url($template->value) : null;

        $layoutSetting = \App\Models\Setting::where('key', 'print_layout_invoice')->first();
        $layout = $layoutSetting?->value ? json_decode($layoutSetting->value, true) : null;

        return Inertia::render('Invoices/Print', [
            'invoice' => $invoice,
            'templateUrl' => $templateUrl,
            'layout' => $layout,
            'roomTypes' => OfferOption::ROOM_TYPES,
        ]);
    }

    // ==================== فاتورة العرض ====================

    /** معاينة حيّة: نفس OfferPricing الذي يحفظ الفاتورة، فلا حساب ثانٍ في الواجهة */
    public function offerPreview(Request $request)
    {
        $data = $request->validate([
            'offer_id' => 'required|integer|exists:offers,id',
            'invoice_id' => 'nullable|integer',
            'lines' => 'array',
            'extras' => 'nullable|array',
            'discount' => 'nullable|numeric|min:0',
            'refresh' => 'boolean',
        ]);
        $offer = Offer::findOrFail($data['offer_id']);
        $invoice = !empty($data['invoice_id']) ? Invoice::where('offer_id', $offer->id)->find($data['invoice_id']) : null;

        $b = OfferPricing::build($offer, $data['lines'] ?? [], $data['extras'] ?? [], (float) ($data['discount'] ?? 0),
            $invoice?->offer_lines['lines'] ?? [], (bool) ($data['refresh'] ?? false));
        $names = Agent::whereIn('id', collect($b['items'])->pluck('agent_id')->filter()->unique())->pluck('name', 'id');

        // التكلفة الحالية لسطر محفوظ — لينبّه الموظف إن تغيّرت منذ البيع
        $lines = array_map(function ($l) use ($offer) {
            $now = $l['kept'] ? OfferPricing::priceLine($offer, $l['sel'], $l['room'] ?? 'single') : null;

            return [
                'sell_pp' => $l['sell_pp'], 'cost_pp' => $l['cost_pp'], 'kept' => $l['kept'], 'pax' => $l['pax'],
                'cost_now' => $now && !$now['errors'] ? $now['cost_pp'] : null,
            ];
        }, $b['lines']);

        return response()->json([
            'errors' => $b['errors'],
            'lines' => $lines,
            'agents' => collect($b['items'])->groupBy('agent_id')->map(fn ($its, $a) => [
                'agent' => $names[$a] ?? 'بلا وكيل',
                'total' => round($its->sum('total_cost_jod'), 3),
                'items' => $its->map(fn ($it) => [
                    'description' => $it['description'], 'qty' => $it['quantity'], 'unit' => $it['unit_price_jod'],
                    'total' => $it['total_cost_jod'], 'currency' => $it['cost_currency'], 'original' => $it['cost_original'],
                ])->values(),
            ])->values(),
            'gross' => $b['gross'], 'discount' => $b['discount'], 'sell' => $b['sell'],
            'cost' => $b['cost'], 'profit' => $b['profit'], 'pax' => $b['pax'],
            'available' => $offer->availableSeats($invoice?->id),
        ]);
    }

    private function offerRules(): array
    {
        return [
            'client_name' => 'required|string|max:255',
            'client_phone' => 'required|string|max:20',
            'sold_by' => 'nullable|exists:employees,id',
            'trip_date' => 'required|date',
            'offer_id' => 'required|integer|exists:offers,id',
            'lines' => 'required|array|min:1|max:10',
            'lines.*.sel' => 'nullable|array',
            'lines.*.room' => 'nullable|string|max:10',
            'lines.*.pax' => 'required|integer|min:1|max:500',
            'extras' => 'nullable|array|max:10',
            'extras.*.agent_id' => 'required|exists:agents,id',
            'extras.*.service_id' => 'nullable|integer',
            'extras.*.description' => 'required|string|max:255',
            'extras.*.qty' => 'required|integer|min:1',
            'extras.*.cost' => 'required|numeric|min:0',
            'extras.*.sell' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'quote_request_id' => 'nullable|integer|exists:quote_requests,id',
            'refresh_costs' => 'boolean',
        ];
    }

    private function offerMessages(): array
    {
        return [
            'client_name.required' => 'اسم العميل مطلوب.',
            'client_phone.required' => 'رقم هاتف العميل مطلوب.',
            'trip_date.required' => 'تاريخ الرحلة مطلوب.',
            'offer_id.required' => 'اختر العرض.',
            'lines.required' => 'أضف سطر حجز واحداً على الأقل.',
            'extras.*.agent_id.required' => 'الوكيل مطلوب في البند الإضافي.',
        ];
    }

    /**
     * يحفظ فاتورة عرض (جديدة أو تعديلاً). أول كتابة في المعاملة على صف العرض
     * تحجز قفل الكتابة في SQLite، فلا يُباع المقعد نفسه مرتين، ولا يتغير updated_at.
     */
    private function saveOfferInvoice(array $v, ?Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($v, $invoice) {
            DB::table('offers')->where('id', $v['offer_id'])->increment('seats_version');

            $offer = Offer::with(['groups.options.stays', 'groups.options.costs', 'fixedCosts'])->findOrFail($v['offer_id']);
            $ignore = $invoice?->id;
            $errors = [];

            if ($invoice && (int) $invoice->offer_id !== $offer->id) {
                $errors[] = 'لا يتغير عرض الفاتورة في التعديل.';
            }
            $today = now()->toDateString();
            if ($offer->isClosed()) $errors[] = 'العرض مُقفل.';
            if (!$offer->is_active) $errors[] = 'العرض غير فعّال.';
            if ($offer->is_ready) {
                if ($v['trip_date'] !== $offer->trip_date?->toDateString()) $errors[] = 'تاريخ الرحلة لا يطابق تاريخ العرض الجاهز.';
                if (!$invoice && $offer->trip_date && $offer->trip_date->toDateString() <= $today) $errors[] = 'أقلعت رحلة هذا العرض.';
            } else {
                if ($offer->valid_from && $v['trip_date'] < $offer->valid_from->toDateString()) $errors[] = 'تاريخ الرحلة قبل بداية مدة العرض.';
                if ($offer->valid_to && $v['trip_date'] > $offer->valid_to->toDateString()) $errors[] = 'تاريخ الرحلة بعد نهاية مدة العرض.';
            }
            foreach (OfferPricing::issues($offer) as $i) {
                $errors[] = "تكاليف العرض غير مكتملة: {$i}";
                break;
            }

            $b = OfferPricing::build($offer, $v['lines'], $v['extras'] ?? [], (float) ($v['discount'] ?? 0),
                $invoice?->offer_lines['lines'] ?? [], (bool) ($v['refresh_costs'] ?? false));
            $errors = array_merge($errors, $b['errors']);

            if ($offer->total_seats) {
                $booked = $offer->bookedSeats($ignore);
                if ($booked + $b['pax'] > $offer->total_seats) {
                    $left = max(0, $offer->total_seats - $booked);
                    $errors[] = "المتاح في العرض {$left} مقعداً فقط، والفاتورة تطلب {$b['pax']}.";
                }
            }
            foreach ($offer->groups as $g) {
                foreach ($g->options as $opt) {
                    if (!$opt->capacity) continue;
                    $req = collect($b['lines'])->filter(fn ($l) => (int) ($l['sel'][$g->id] ?? 0) === $opt->id)->sum('pax');
                    $used = OfferPricing::optionUsage($offer, $opt->id, $ignore);
                    if ($req && $used + $req > $opt->capacity) {
                        $errors[] = "سعة «{$opt->label}» {$opt->capacity} والمحجوز فيها {$used}، فلا تتسع لـ {$req}.";
                    }
                }
            }
            if (!$errors && $b['profit'] < 0) {
                $errors[] = 'سعر البيع بعد الخصم أقل من التكلفة (' . number_format($b['cost'], 3) . ' د.أ). قلّل الخصم.';
            }
            if ($errors) {
                throw ValidationException::withMessages(['offer' => array_values(array_unique($errors))]);
            }

            // العميل: بالهاتف، وإلا يُنشأ بالاسم والرقم
            $client = Client::findByPhone($v['client_phone']);
            if (!$client && $invoice) {
                $client = $invoice->client;
            }
            if (!$client) {
                $client = Client::create([
                    'name' => trim($v['client_name']),
                    'phone' => trim($v['client_phone']),
                    'code' => Client::nextCode(),
                    'country' => 'JO',
                    'currency' => 'JOD',
                    'balance_jod' => 0,
                    'credit_limit_jod' => 0,
                    'is_active' => true,
                    'created_by' => auth()->id(),
                ]);
            }

            $rate = self::rate();
            $snapshot = [
                'offer_title' => $offer->title,
                'trip_date' => $v['trip_date'],
                'lines' => $b['lines'],
                'extras' => $b['extras'],
                'gross' => $b['gross'],
                'discount' => $b['discount'],
                'sell' => $b['sell'],
                'cost' => $b['cost'],
                'pax' => $b['pax'],
            ];
            $fields = [
                'client_id' => $client->id,
                'client_phone' => trim($v['client_phone']),
                'trip_date' => $v['trip_date'],
                'exchange_rate_snapshot' => $rate,
                'total_cost_jod' => $b['cost'],
                'total_cost_sar' => round($b['cost'] / $rate, 2),
                // الخصم مطروح من البيع نفسه — لا يُوضع في discount_jod الذي يطرحه الحساب من الربح وحده
                'total_sell_jod' => $b['sell'],
                'discount_jod' => 0,
                'profit_jod' => $b['profit'],
                'subtotal_sar' => round($b['cost'] / $rate, 2),
                'discount_sar' => 0,
                'total_sar' => round($b['cost'] / $rate, 2),
                'total_jod' => $b['sell'],
                'services_cost_sar' => round($b['cost'] / $rate, 2),
                'profit_sar' => round($b['profit'] / $rate, 2),
                'notes' => $v['notes'] ?? null,
                'sold_by' => $v['sold_by'] ?? $invoice?->sold_by ?? auth()->user()->employee?->id,
                'offer_id' => $offer->id,
                'offer_seats' => $b['pax'],
                'offer_lines' => $snapshot,
                'status' => 'pending',
            ];

            if ($invoice) {
                $invoice->update($fields + ['approved_by' => null, 'approved_at' => null]);
                $invoice->items()->delete();
            } else {
                $invoice = Invoice::create($fields + [
                    'invoice_number' => NumberingService::generate('INV'),
                    'invoice_date' => now()->toDateString(),
                    'created_by' => auth()->id(),
                    'quote_request_id' => $v['quote_request_id'] ?? null,
                ]);
            }

            foreach ($b['items'] as $i => $it) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'agent_id' => $it['agent_id'],
                    'item_type' => 'service',
                    'service_id' => $it['service_id'],
                    'description' => $it['description'],
                    'statement' => $it['statement'],
                    'quantity' => $it['quantity'],
                    'unit_price_jod' => $it['unit_price_jod'],
                    'total_cost_jod' => $it['total_cost_jod'],
                    'unit_price_sar' => round($it['unit_price_jod'] / $rate, 2),
                    'total_cost_sar' => round($it['total_cost_jod'] / $rate, 2),
                    'sell_price_jod' => 0,
                    'total_sell_jod' => 0,
                    'sort_order' => $i + 1,
                    'source_type' => $it['source_type'],
                    'source_id' => $it['source_id'],
                    'cost_currency' => $it['cost_currency'],
                    'cost_original' => $it['cost_original'],
                    'fx_rate' => $it['fx_rate'],
                ]);
            }

            if (!empty($v['quote_request_id'])) {
                QuoteRequest::whereKey($v['quote_request_id'])->update([
                    'status' => 'won',
                    'invoice_id' => $invoice->id,
                    'client_id' => $client->id,
                ]);
            }

            return $invoice;
        });
    }

    // ==================== الحفظ ====================

    public function store(Request $request)
    {
        if ($request->input('mode') === 'offer') {
            $v = $request->validate($this->offerRules(), $this->offerMessages());
            $invoice = $this->saveOfferInvoice($v, null);
            try { \App\Services\NotificationService::invoiceCreated($invoice); } catch (\Throwable $e) {}
            AuditLog::log('create', 'invoice', $invoice->id, $invoice->invoice_number);

            return redirect()->back()->with('success', "تم إنشاء فاتورة العرض {$invoice->invoice_number} وحُجزت مقاعدها ({$invoice->offer_seats})");
        }

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'client_phone' => 'nullable|string|max:20',
            'trip_date' => 'required|date',
            'sold_by' => 'nullable|exists:employees,id',
            'sell_price_jod' => 'required|numeric|min:0',
            'discount_jod' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.agent_id' => 'required|exists:agents,id',
            'items.*.description' => 'required|string',
            'items.*.statement' => 'nullable|string|max:1000',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price_jod' => 'required|numeric|min:0',
            'items.*.service_id' => 'nullable|integer',
        ], [
            'trip_date.required' => 'تاريخ السفر مطلوب لحفظ الفاتورة',
        ]);

        DB::transaction(function () use ($validated) {
            $rate = self::rate();
            $discountJod = $validated['discount_jod'] ?? 0;
            $sellPriceJod = $validated['sell_price_jod'];

            // حساب الإجماليات
            $totalCostJod = 0;
            foreach ($validated['items'] as $item) {
                $totalCostJod += $item['quantity'] * $item['unit_price_jod'];
            }

            $totalCostSar = round($totalCostJod / $rate, 2); // للتوافق
            $profitJod = round($sellPriceJod - $totalCostJod - $discountJod, 3);

            $invoice = Invoice::create([
                'invoice_number' => NumberingService::generate('INV'),
                'client_id' => $validated['client_id'],
                'client_phone' => $validated['client_phone'] ?? null,
                'trip_date' => $validated['trip_date'] ?? null,
                'exchange_rate_snapshot' => $rate,
                'total_cost_jod' => $totalCostJod,
                'total_cost_sar' => $totalCostSar,
                'total_sell_jod' => $sellPriceJod,
                'discount_jod' => $discountJod,
                'profit_jod' => $profitJod,
                // legacy fields
                'subtotal_sar' => $totalCostSar,
                'discount_sar' => 0,
                'total_sar' => $totalCostSar,
                'total_jod' => $sellPriceJod,
                'services_cost_sar' => $totalCostSar,
                'profit_sar' => $rate > 0 ? round($profitJod / $rate, 2) : 0,
                'invoice_date' => now()->toDateString(),
                'status' => 'pending',
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
                // البائع المُختار، وإلا موظف المُنشئ إن وُجد
                'sold_by' => $validated['sold_by'] ?? auth()->user()->employee?->id,
            ]);

            // حفظ البنود
            foreach ($validated['items'] as $i => $item) {
                $costJod = $item['quantity'] * $item['unit_price_jod'];
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'agent_id' => $item['agent_id'],
                    'item_type' => 'service',
                    'service_id' => $item['service_id'] ?? null,
                    'description' => $item['description'],
                    'statement' => $item['statement'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price_jod' => $item['unit_price_jod'],
                    'total_cost_jod' => $costJod,
                    // legacy SAR fields
                    'unit_price_sar' => round($item['unit_price_jod'] / $rate, 2),
                    'total_cost_sar' => round($costJod / $rate, 2),
                    'sell_price_jod' => 0,
                    'total_sell_jod' => 0,
                    'sort_order' => $i + 1,
                    'source_type' => 'manual',
                ]);
            }

            // إشعار بانتظار الاعتماد
            try { \App\Services\NotificationService::invoiceCreated($invoice); } catch (\Exception $e) {}
        });

        return redirect()->back()->with('success', 'تم إنشاء الفاتورة بنجاح');
    }

    public function approve(Invoice $invoice)
    {
        if (!$invoice->isPending()) {
            return back()->with('error', 'هذه الفاتورة ليست معلقة');
        }

        try {
            DB::transaction(function () use ($invoice) {
                $invoice->update([
                    'status' => 'approved',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);

                $invoice->load('items');
                $rate = self::rate();

                // تجميع تكاليف كل وكيل وخصمها (تحويل JOD → SAR)
                $agentCosts = [];
                foreach ($invoice->items as $item) {
                    if ($item->agent_id) {
                        $costJod = (float) ($item->total_cost_jod ?: $item->total_cost_sar * $rate);
                        $agentCosts[$item->agent_id] = ($agentCosts[$item->agent_id] ?? 0) + $costJod;
                    }
                }

                foreach ($agentCosts as $agentId => $costJod) {
                    $agent = Agent::find($agentId);
                    if ($agent && $costJod > 0) {
                        $costSar = round($costJod / $rate, 2);
                        BalanceService::debitAgent($agent, $costSar, 'invoice', $invoice->id);
                    }
                }

                // إضافة إجمالي البيع على ذمة العميل
                $sellJod = (float) $invoice->total_sell_jod;
                if ($sellJod > 0) {
                    BalanceService::debitClient(
                        $invoice->client,
                        $sellJod,
                        'invoice',
                        $invoice->id
                    );
                }

                // قيد محاسبي — إلزامي: فشله يُلغي الاعتماد بالكامل (لا تباعد بين الدفترين)
                AccountingService::recordInvoice($invoice);

                // إشعار
                if ($invoice->created_by && $invoice->created_by !== auth()->id()) {
                    try { \App\Services\NotificationService::send($invoice->created_by, '✅ تم اعتماد فاتورتك', "تم اعتماد الفاتورة {$invoice->invoice_number}", ['type' => 'invoice', 'icon' => '✅', 'action_url' => '/invoices']); } catch (\Throwable $e) {}
                }
            });
        } catch (\Throwable $e) {
            AccountingService::notifyFailure("اعتماد الفاتورة {$invoice->invoice_number}", $e);
            return back()->with('error', 'تعذّر اعتماد الفاتورة محاسبياً ولم يُحفظ أي تغيير. تم إشعار الإدارة: ' . $e->getMessage());
        }

        AuditLog::log('approve', 'invoice', $invoice->id, $invoice->invoice_number);
        return back()->with('success', "تم اعتماد الفاتورة {$invoice->invoice_number}");
    }

    public function reject(Request $request, Invoice $invoice)
    {
        if (!$invoice->isPending()) {
            return back()->with('error', 'هذه الفاتورة ليست معلقة');
        }
        $invoice->update([
            'status' => 'rejected',
            'rejection_reason' => $request->input('reason', ''),
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        try { \App\Services\NotificationService::operationRejected($invoice, 'فاتورة', $invoice->invoice_number); } catch (\Throwable $e) {}

        return back()->with('success', 'تم رفض الفاتورة' . ($invoice->offer_id ? '، وعادت مقاعدها للمتاح' : ''));
    }

    public function startEdit(Invoice $invoice)
    {
        if (!$invoice->isApproved()) {
            return back()->with('error', 'يمكن تعديل الفواتير المعتمدة فقط');
        }

        DB::transaction(function () use ($invoice) {
            $invoice->load('items');
            $rate = self::rate();

            // عكس خصم كل وكيل
            $agentCosts = [];
            foreach ($invoice->items as $item) {
                if ($item->agent_id) {
                    $costJod = (float) ($item->total_cost_jod ?: $item->total_cost_sar * $rate);
                    $agentCosts[$item->agent_id] = ($agentCosts[$item->agent_id] ?? 0) + $costJod;
                }
            }
            foreach ($agentCosts as $agentId => $costJod) {
                $agent = Agent::find($agentId);
                if ($agent && $costJod > 0) {
                    $costSar = round($costJod / $rate, 2);
                    BalanceService::reverseAgentDebit($agent, $costSar, 'invoice', $invoice->id);
                }
            }

            // عكس ذمة العميل
            $sellJod = (float) $invoice->total_sell_jod;
            if ($sellJod > 0) {
                BalanceService::reverseClientDebit($invoice->client, $sellJod, 'invoice', $invoice->id);
            }

            // عكس القيد المحاسبي
            $entry = \App\Models\JournalEntry::where('reference_type', 'invoice')
                ->where('reference_id', $invoice->id)->where('is_reversed', false)->first();
            if ($entry) try { AccountingService::reverseEntry($entry, 'تعديل الفاتورة'); } catch (\Exception $e) {}

            $invoice->startEditing(auth()->user());
            AuditLog::log('start_edit', 'invoice', $invoice->id, $invoice->invoice_number);
        });

        return back()->with('success', "تم فتح الفاتورة {$invoice->invoice_number} للتعديل");
    }

    public function update(Request $request, Invoice $invoice)
    {
        if ($invoice->status !== 'editing' && $invoice->status !== 'pending') {
            return back()->with('error', 'لا يمكن تعديل هذه الفاتورة');
        }

        if ($invoice->offer_id) {
            $v = $request->validate($this->offerRules(), $this->offerMessages());
            $this->saveOfferInvoice($v, $invoice);
            AuditLog::log('update', 'invoice', $invoice->id, $invoice->invoice_number);

            return redirect()->back()->with('success', "تم تعديل الفاتورة {$invoice->invoice_number} وإرسالها للاعتماد");
        }

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'client_phone' => 'nullable|string|max:20',
            'trip_date' => 'required|date',
            'sold_by' => 'nullable|exists:employees,id',
            'sell_price_jod' => 'required|numeric|min:0',
            'discount_jod' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.agent_id' => 'required|exists:agents,id',
            'items.*.description' => 'required|string',
            'items.*.statement' => 'nullable|string|max:1000',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price_jod' => 'required|numeric|min:0',
            'items.*.service_id' => 'nullable|integer',
        ], [
            'trip_date.required' => 'تاريخ السفر مطلوب لحفظ الفاتورة',
        ]);

        DB::transaction(function () use ($invoice, $validated) {
            $rate = self::rate();
            $discountJod = $validated['discount_jod'] ?? 0;
            $sellPriceJod = $validated['sell_price_jod'];

            $totalCostJod = 0;
            foreach ($validated['items'] as $item) {
                $totalCostJod += $item['quantity'] * $item['unit_price_jod'];
            }

            $totalCostSar = round($totalCostJod / $rate, 2);
            $profitJod = round($sellPriceJod - $totalCostJod - $discountJod, 3);

            $invoice->update([
                'client_id' => $validated['client_id'],
                'client_phone' => $validated['client_phone'] ?? null,
                'trip_date' => $validated['trip_date'] ?? null,
                'exchange_rate_snapshot' => $rate,
                'total_cost_jod' => $totalCostJod,
                'total_cost_sar' => $totalCostSar,
                'total_sell_jod' => $sellPriceJod,
                'discount_jod' => $discountJod,
                'profit_jod' => $profitJod,
                'subtotal_sar' => $totalCostSar,
                'total_sar' => $totalCostSar,
                'total_jod' => $sellPriceJod,
                'services_cost_sar' => $totalCostSar,
                'profit_sar' => $rate > 0 ? round($profitJod / $rate, 2) : 0,
                'notes' => $validated['notes'] ?? null,
                'sold_by' => $validated['sold_by'] ?? $invoice->sold_by ?? auth()->user()->employee?->id,
                'status' => 'pending',
                'approved_by' => null,
                'approved_at' => null,
            ]);

            $invoice->items()->delete();
            foreach ($validated['items'] as $i => $item) {
                $costJod = $item['quantity'] * $item['unit_price_jod'];
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'agent_id' => $item['agent_id'],
                    'item_type' => 'service',
                    'service_id' => $item['service_id'] ?? null,
                    'description' => $item['description'],
                    'statement' => $item['statement'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price_jod' => $item['unit_price_jod'],
                    'total_cost_jod' => $costJod,
                    'unit_price_sar' => round($item['unit_price_jod'] / $rate, 2),
                    'total_cost_sar' => round($costJod / $rate, 2),
                    'sell_price_jod' => 0,
                    'total_sell_jod' => 0,
                    'sort_order' => $i + 1,
                    'source_type' => 'manual',
                ]);
            }

            AuditLog::log('update', 'invoice', $invoice->id, $invoice->invoice_number);
        });

        return redirect()->back()->with('success', "تم تعديل الفاتورة {$invoice->invoice_number} وإرسالها للاعتماد");
    }

    public function destroy(Invoice $invoice)
    {
        if ($invoice->isApproved()) {
            return back()->with('error', 'لا يمكن حذف فاتورة معتمدة');
        }
        $invoice->items()->delete();
        $invoice->delete();
        return back()->with('success', 'تم حذف الفاتورة' . ($invoice->offer_id ? '، وعادت مقاعدها للمتاح' : ''));
    }
}
