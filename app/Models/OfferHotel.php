<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferHotel extends Model
{
    /** أنواع الغرف الثابتة — السعر دائماً «للفرد» */
    public const ROOM_TYPES = [
        'single' => 'مفردة',
        'double' => 'ثنائية',
        'triple' => 'ثلاثية',
        'quad'   => 'رباعية',
    ];

    protected $fillable = [
        'offer_id', 'name', 'rating', 'rating_plus', 'location', 'meals',
        'distance_haram', 'includes_note', 'prices', 'sort_order',
    ];

    protected $casts = [
        'prices' => 'array',
        'rating_plus' => 'boolean',
    ];

    /** التصنيف كما يُكتب في البطاقة: «4+» أو «4» */
    public function ratingLabel(): ?string
    {
        return $this->rating ? $this->rating . ($this->rating_plus ? '+' : '') : null;
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** الأسعار المتاحة فقط (غير الفارغة) مرتّبة بترتيب أنواع الغرف */
    public function availablePrices(): array
    {
        $out = [];
        foreach (self::ROOM_TYPES as $key => $label) {
            $v = $this->prices[$key] ?? null;
            if ($v !== null && $v !== '' && (float) $v > 0) {
                $out[$key] = ['label' => $label, 'price_per_person_jod' => round((float) $v, 3)];
            }
        }

        return $out;
    }

    /** أقل سعر للفرد في هذا الفندق */
    public function minPrice(): ?float
    {
        $p = array_column($this->availablePrices(), 'price_per_person_jod');
        return $p ? min($p) : null;
    }

    /** ما يُعاد للبوت — لا يوجد في هذا الجدول أي حقل داخلي */
    public function toBotArray(): array
    {
        return [
            'hotel' => $this->name,
            'rating' => $this->ratingLabel(),
            'location' => $this->location,
            'meals' => $this->meals,
            'distance_from_haram' => $this->distance_haram,
            'price_includes' => $this->includes_note,
            'prices_per_person_jod' => collect($this->availablePrices())
                ->mapWithKeys(fn ($v, $k) => [$v['label'] => $v['price_per_person_jod']])
                ->all(),
        ];
    }
}
