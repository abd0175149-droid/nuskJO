<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\OfferHotel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OfferController extends Controller
{
    private const CATEGORIES = ['package', 'umrah', 'hajj', 'flight', 'visa', 'hotel', 'transport', 'tour'];

    public function index(Request $request)
    {
        abort_unless(auth()->user()->can('offers.view'), 403);

        $offers = Offer::with('hotels')
            ->when($request->search, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->orderBy('sort_order')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        $offers->getCollection()->transform(function ($o) {
            $arr = $o->toArray();
            $arr['price_from'] = $o->priceFrom();
            $arr['hotels_count'] = $o->hotels->count();
            $arr['hotels'] = $o->hotels->map(fn ($h) => [
                'id' => $h->id,
                'name' => $h->name,
                'rating' => $h->rating,
                'includes_note' => $h->includes_note,
                'prices' => $h->prices ?: [],
                'sort_order' => $h->sort_order,
                'min_price' => $h->minPrice(),
            ])->values()->all();
            return $arr;
        });

        return Inertia::render('Offers/Index', [
            'title' => 'العروض والباقات',
            'offers' => $offers,
            'filters' => $request->only(['search', 'category']),
            'categories' => self::CATEGORIES,
            'roomTypes' => OfferHotel::ROOM_TYPES,
            'can' => [
                'create' => auth()->user()->can('offers.create'),
                'update' => auth()->user()->can('offers.update'),
                'delete' => auth()->user()->can('offers.delete'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->can('offers.create'), 403);

        $data = $this->validated($request);
        $hotels = $data['hotels'] ?? [];
        unset($data['hotels']);
        $data['created_by'] = auth()->id();

        DB::transaction(function () use ($data, $hotels) {
            $offer = Offer::create($data);
            $this->syncHotels($offer, $hotels);
        });

        return back()->with('success', 'تم إضافة العرض');
    }

    public function update(Request $request, Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.update'), 403);

        $data = $this->validated($request);
        $hotels = $data['hotels'] ?? [];
        unset($data['hotels']);

        DB::transaction(function () use ($offer, $data, $hotels) {
            $offer->update($data);
            $this->syncHotels($offer, $hotels);
        });

        return back()->with('success', 'تم تحديث العرض');
    }

    public function destroy(Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.delete'), 403);

        DB::transaction(function () use ($offer) {
            $offer->hotels()->delete();
            $offer->delete();
        });

        return back()->with('success', 'تم حذف العرض');
    }

    public function toggleBot(Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.update'), 403);

        // لا يُنشر للبوت عرضٌ بلا فندق واحد على الأقل بسعر
        if (!$offer->is_bot_visible && !$offer->priceFrom()) {
            return back()->with('error', 'أضف فندقاً واحداً على الأقل بسعر قبل إظهار العرض للبوت.');
        }

        $offer->update(['is_bot_visible' => !$offer->is_bot_visible]);

        return back()->with('success', $offer->is_bot_visible ? 'العرض ظاهر للبوت الآن' : 'أُخفي العرض عن البوت');
    }

    /** استبدال كامل لفنادق العرض (العروض صغيرة ولا تبعيات عليها) */
    private function syncHotels(Offer $offer, array $hotels): void
    {
        $offer->hotels()->delete();

        foreach (array_values($hotels) as $i => $h) {
            $prices = [];
            foreach (array_keys(OfferHotel::ROOM_TYPES) as $rt) {
                $v = $h['prices'][$rt] ?? null;
                $prices[$rt] = ($v === null || $v === '') ? null : round((float) $v, 3);
            }

            $offer->hotels()->create([
                'name' => $h['name'],
                'rating' => $h['rating'] ?? null,
                'includes_note' => $h['includes_note'] ?? null,
                'prices' => $prices,
                'sort_order' => $i,
            ]);
        }
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:180',
            'category' => 'required|in:' . implode(',', self::CATEGORIES),
            'description_client' => 'nullable|string|max:4000',
            'nights' => 'nullable|integer|min:0|max:365',
            'airline' => 'nullable|string|max:80',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'includes' => 'nullable|array',
            'includes.*' => 'string|max:120',
            'excludes' => 'nullable|array',
            'excludes.*' => 'string|max:120',
            'is_active' => 'boolean',
            'is_bot_visible' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:9999',

            // الفنادق وأسعارها للفرد حسب سعة الغرفة
            'hotels' => 'nullable|array|max:20',
            'hotels.*.name' => 'required|string|max:150',
            'hotels.*.rating' => 'nullable|integer|min:1|max:7',
            'hotels.*.includes_note' => 'nullable|string|max:500',
            'hotels.*.prices' => 'nullable|array',
            'hotels.*.prices.single' => 'nullable|numeric|min:0|max:999999',
            'hotels.*.prices.double' => 'nullable|numeric|min:0|max:999999',
            'hotels.*.prices.triple' => 'nullable|numeric|min:0|max:999999',
            'hotels.*.prices.quad'   => 'nullable|numeric|min:0|max:999999',
        ], [
            'hotels.*.name.required' => 'اسم الفندق مطلوب لكل فندق مُضاف.',
        ]);
    }
}
