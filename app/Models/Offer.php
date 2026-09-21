<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offer extends Model
{
    protected $fillable = [
        'title', 'category', 'description_client',
        'includes', 'excludes',
        'valid_from', 'valid_to',
        'nights', 'airline',
        'is_active', 'is_bot_visible', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'includes' => 'array',
        'excludes' => 'array',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'is_active' => 'boolean',
        'is_bot_visible' => 'boolean',
    ];

    public function hotels(): HasMany
    {
        return $this->hasMany(OfferHotel::class)->orderBy('sort_order')->orderBy('id');
    }

    /** العروض المسموح بظهورها للبوت والصالحة زمنياً */
    public function scopeForBot($q)
    {
        $today = now()->toDateString();

        return $q->where('is_active', true)
            ->where('is_bot_visible', true)
            ->where(function ($w) use ($today) {
                $w->whereNull('valid_to')->orWhere('valid_to', '>=', $today);
            })
            ->where(function ($w) use ($today) {
                $w->whereNull('valid_from')->orWhere('valid_from', '<=', $today);
            });
    }

    /** «يبدأ من» — أقل سعر للفرد عبر كل فنادق العرض */
    public function priceFrom(): ?float
    {
        $mins = $this->hotels->map(fn ($h) => $h->minPrice())->filter()->all();
        return $mins ? min($mins) : null;
    }

    /**
     * ما يُعاد للبوت. لم يعد هناك أي حقل داخلي في العروض إطلاقاً،
     * والسعر دائماً «للفرد حسب سعة الغرفة» ضمن كل فندق.
     */
    public function toBotArray(): array
    {
        $this->loadMissing('hotels');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'description' => $this->description_client,
            'nights' => $this->nights,
            'airline' => $this->airline,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'includes' => $this->includes ?: [],
            'excludes' => $this->excludes ?: [],
            'price_from_per_person_jod' => $this->priceFrom(),
            'pricing_note' => 'الأسعار للفرد الواحد وتختلف حسب سعة الغرفة والفندق.',
            'hotels' => $this->hotels->map(fn ($h) => $h->toBotArray())->values()->all(),
        ];
    }
}
