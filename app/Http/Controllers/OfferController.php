<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Offer;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OfferController extends Controller
{
    private const CATEGORIES = ['package', 'umrah', 'hajj', 'flight', 'visa', 'hotel', 'transport', 'tour'];

    public function index(Request $request)
    {
        abort_unless(auth()->user()->can('offers.view'), 403);

        $offers = Offer::with('agent:id,name')
            ->when($request->search, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->orderBy('sort_order')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return Inertia::render('Offers/Index', [
            'title' => 'العروض والباقات',
            'offers' => $offers,
            'filters' => $request->only(['search', 'category']),
            'agents' => Agent::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => self::CATEGORIES,
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
        $data['created_by'] = auth()->id();
        Offer::create($data);

        return back()->with('success', 'تم إضافة العرض');
    }

    public function update(Request $request, Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.update'), 403);
        $offer->update($this->validated($request));

        return back()->with('success', 'تم تحديث العرض');
    }

    public function destroy(Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.delete'), 403);
        $offer->delete();

        return back()->with('success', 'تم حذف العرض');
    }

    /** مفتاح سريع: هل يعرضه البوت؟ */
    public function toggleBot(Offer $offer)
    {
        abort_unless(auth()->user()->can('offers.update'), 403);
        $offer->update(['is_bot_visible' => !$offer->is_bot_visible]);

        return back()->with('success', $offer->is_bot_visible ? 'العرض ظاهر للبوت الآن' : 'أُخفي العرض عن البوت');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:180',
            'category' => 'required|in:' . implode(',', self::CATEGORIES),
            'description_client' => 'nullable|string|max:4000',
            'price_jod' => 'required|numeric|min:0',
            'price_per' => 'required|in:person,room,group',
            'includes' => 'nullable|array',
            'includes.*' => 'string|max:120',
            'excludes' => 'nullable|array',
            'excludes.*' => 'string|max:120',
            'departure_date' => 'nullable|date',
            'return_date' => 'nullable|date|after_or_equal:departure_date',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'nights' => 'nullable|integer|min:0|max:365',
            'hotel_name' => 'nullable|string|max:120',
            'hotel_rating' => 'nullable|integer|min:1|max:7',
            'airline' => 'nullable|string|max:80',
            'agent_id' => 'nullable|exists:agents,id',
            'available_seats' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'is_bot_visible' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            // داخلي — لا يُعاد للبوت
            'cost_jod' => 'nullable|numeric|min:0',
            'notes_internal' => 'nullable|string|max:2000',
        ]);
    }
}
