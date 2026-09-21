<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\OfferOption;
use App\Services\OfferCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OfferController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()->can('offers.view'), 403);

        $offers = Offer::with('options.stays')
            ->when($request->search, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->orderBy('sort_order')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        $offers->getCollection()->transform(fn ($o) => $this->rowFor($o));

        return Inertia::render('Offers/Index', [
            'title' => 'العروض والباقات',
            'offers' => $offers,
            'filters' => $request->only(['search', 'category']),
            'categories' => Offer::CATEGORIES,
            'routeModes' => Offer::ROUTE_MODES,
            'routeCities' => Offer::ROUTE_CITIES,
            'roomTypes' => OfferOption::ROOM_TYPES,
            'can' => [
                'create' => auth()->user()->can('offers.create'),
                'update' => auth()->user()->can('offers.update'),
                'delete' => auth()->user()->can('offers.delete'),
            ],
        ]);
    }

    /** صفّ العرض كما تحتاجه الواجهة */
    private function rowFor(Offer $o): array
    {
        $arr = $o->toArray();

        $arr['category_label'] = $o->categoryLabel();
        $arr['price_from'] = $o->priceFrom();
        $arr['is_visa'] = $o->isVisa();
        $arr['uses_hotels'] = $o->usesHotels();
        $arr['uses_haram_distance'] = $o->usesHaramDistance();
        $arr['is_multi_city'] = $o->isMultiCity();
        $arr['options_count'] = $o->options->count();

        $arr['options'] = $o->options->map(fn ($opt) => [
            'id' => $opt->id,
            'includes_note' => $opt->includes_note,
            'prices' => $opt->prices ?: [],
            'sort_order' => $opt->sort_order,
            'min_price' => $opt->minPrice(),
            'label' => $opt->label(),
            'stays' => $opt->stays->map(fn ($s) => [
                'id' => $s->id,
                'city' => $s->city,
                'name' => $s->name,
                'rating' => $s->rating,
                'rating_plus' => (bool) $s->rating_plus,
                'location' => $s->location,
                'meals' => $s->meals,
                'distance_haram' => $s->distance_haram,
                'sort_order' => $s->sort_order,
            ])->values()->all(),
        ])->values()->all();

        // بطاقة العرض
        $card = $o->cardImage();
        $stamp = $o->card_generated_at?->timestamp ?: $o->updated_at?->timestamp;
        $arr['card_url'] = $card ? Storage::disk('public')->url($card) . '?v=' . $stamp : null;
        $arr['card_is_custom'] = (bool) $o->custom_card_path;
        $arr['card_is_stale'] = $o->cardIsStale();
        $arr['hero_url'] = $o->hero_path ? Storage::disk('public')->url($o->hero_path) : null;

        return $arr;
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->can('offers.create'), 403);

        $data = $this->validated($request);
        $options = $data['options'] ?? [];
        unset($data['options']);
        $data['created_by'] = auth()->id();

        DB::transaction(function () use ($data, $options) {
            $offer = Offer::create($data);
            $this->syncOptions($offer, $options);
        });

        return back()->with('success', 'تم إضافة العرض');
    }

    public function update(Request $request, Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.update'), 403);

        $data = $this->validated($request);
        $options = $data['options'] ?? [];
        unset($data['options']);

        DB::transaction(function () use ($offer, $data, $options) {
            $offer->update($data);
            $this->syncOptions($offer, $options);
        });

        return back()->with('success', 'تم تحديث العرض');
    }

    public function destroy(Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.delete'), 403);

        OfferCardService::forget($offer);

        DB::transaction(function () use ($offer) {
            $offer->load('options');
            foreach ($offer->options as $opt) {
                $opt->stays()->delete();
            }
            $offer->options()->delete();
            $offer->delete();
        });

        return back()->with('success', 'تم حذف العرض');
    }

    public function toggleBot(Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.update'), 403);

        $offer->loadMissing('options');

        if (!$offer->is_bot_visible && !$offer->priceFrom()) {
            $msg = $offer->isVisa()
                ? 'أدخل سعر التأشيرة قبل إظهار العرض للبوت.'
                : 'أضف خياراً واحداً على الأقل بسعر قبل إظهار العرض للبوت.';

            return back()->with('error', $msg);
        }

        $offer->update(['is_bot_visible' => !$offer->is_bot_visible]);

        return back()->with('success', $offer->is_bot_visible ? 'العرض ظاهر للبوت الآن' : 'أُخفي العرض عن البوت');
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

    // ==================== الحفظ ====================

    /** استبدال كامل لخيارات العرض وإقاماتها (العروض صغيرة ولا تبعيات عليها) */
    private function syncOptions(Offer $offer, array $options): void
    {
        $offer->load('options');
        foreach ($offer->options as $old) {
            $old->stays()->delete();
        }
        $offer->options()->delete();

        // التأشيرات بلا خيارات — سعرها على العرض نفسه
        if ($offer->isVisa()) {
            return;
        }

        foreach (array_values($options) as $i => $opt) {
            $prices = [];
            foreach (array_keys(OfferOption::ROOM_TYPES) as $rt) {
                $v = $opt['prices'][$rt] ?? null;
                $prices[$rt] = ($v === null || $v === '') ? null : round((float) $v, 3);
            }

            $created = $offer->options()->create([
                'includes_note' => $opt['includes_note'] ?? null,
                'prices' => $prices,
                'sort_order' => $i,
            ]);

            foreach (array_values($opt['stays'] ?? []) as $j => $stay) {
                if (blank($stay['name'] ?? null)) {
                    continue;
                }

                $created->stays()->create([
                    'city' => $stay['city'] ?? null,
                    'name' => $stay['name'],
                    'rating' => $stay['rating'] ?? null,
                    'rating_plus' => (bool) ($stay['rating_plus'] ?? false),
                    'location' => $stay['location'] ?? null,
                    'meals' => $stay['meals'] ?? null,
                    // المسافة عن الحرم تخصّ العمرة والحج فقط
                    'distance_haram' => $offer->usesHaramDistance() ? ($stay['distance_haram'] ?? null) : null,
                    'sort_order' => $j,
                ]);
            }
        }
    }

    private function validated(Request $request): array
    {
        $isVisa = $request->input('category') === 'visa';

        $rules = [
            'title' => 'required|string|max:180',
            'category' => ['required', Rule::in(array_keys(Offer::CATEGORIES))],
            'description_client' => 'nullable|string|max:4000',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'includes' => 'nullable|array',
            'includes.*' => 'string|max:120',
            'excludes' => 'nullable|array',
            'excludes.*' => 'string|max:120',
            'notes_public' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'is_bot_visible' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ];

        if ($isVisa) {
            // التأشيرات: سعر واحد للفرد وحقول إصدار، بلا فنادق ولا ليالٍ
            $rules += [
                'requirements' => 'nullable|string|max:4000',
                'visa_validity' => 'nullable|string|max:60',
                'visa_entries' => 'nullable|string|max:40',
                'visa_processing' => 'nullable|string|max:60',
                'price_per_person' => 'nullable|numeric|min:0|max:999999',
            ];
        } else {
            $rules += [
                'nights' => 'nullable|integer|min:0|max:365',
                'airline' => 'nullable|string|max:80',
                'route_mode' => ['nullable', Rule::in(array_keys(Offer::ROUTE_MODES))],

                'options' => 'nullable|array|max:20',
                'options.*.includes_note' => 'nullable|string|max:500',
                'options.*.prices' => 'nullable|array',
                'options.*.prices.single' => 'nullable|numeric|min:0|max:999999',
                'options.*.prices.double' => 'nullable|numeric|min:0|max:999999',
                'options.*.prices.triple' => 'nullable|numeric|min:0|max:999999',
                'options.*.prices.quad'   => 'nullable|numeric|min:0|max:999999',

                'options.*.stays' => 'required|array|min:1|max:4',
                'options.*.stays.*.city' => 'nullable|string|max:60',
                'options.*.stays.*.name' => 'required|string|max:150',
                'options.*.stays.*.rating' => 'nullable|integer|min:1|max:7',
                'options.*.stays.*.rating_plus' => 'boolean',
                'options.*.stays.*.location' => 'nullable|string|max:80',
                'options.*.stays.*.meals' => 'nullable|string|max:60',
                'options.*.stays.*.distance_haram' => 'nullable|string|max:40',
            ];
        }

        $data = $request->validate($rules, [
            'options.*.stays.required' => 'كل خيار يحتاج فندقاً واحداً على الأقل.',
            'options.*.stays.*.name.required' => 'اسم الفندق مطلوب.',
        ]);

        // تنظيف الحقول التي لا تخصّ التصنيف حتى لا تبقى قيم قديمة بعد تغييره
        if ($isVisa) {
            $data += ['nights' => null, 'airline' => null, 'route_mode' => null];
        } else {
            $data += [
                'requirements' => null, 'visa_validity' => null,
                'visa_entries' => null, 'visa_processing' => null, 'price_per_person' => null,
            ];
            if (!in_array($data['category'], ['umrah', 'hajj'], true)) {
                $data['route_mode'] = null;
            }
        }

        return $data;
    }
}
