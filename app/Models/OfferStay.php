<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إقامة: فندق في مدينة، تحت خيار مسعّر.
 *
 * عمرة «مكة فقط» ورحلة سياحية بفندق واحد ← إقامة واحدة.
 * عمرة «مكة والمدينة» ← إقامتان بسعر واحد لهما معاً.
 */
class OfferStay extends Model
{
    protected $fillable = [
        'option_id', 'city', 'name', 'rating', 'rating_plus',
        'location', 'meals', 'distance_haram', 'sort_order',
    ];

    protected $casts = ['rating_plus' => 'boolean'];

    public function option(): BelongsTo
    {
        return $this->belongsTo(OfferOption::class, 'option_id');
    }

    /** التصنيف كما يُكتب في البطاقة: «4+» أو «4» */
    public function ratingLabel(): ?string
    {
        return $this->rating ? $this->rating . ($this->rating_plus ? '+' : '') : null;
    }

    /**
     * ما يُعاد للبوت.
     * @param bool $withDistance المسافة عن الحرم تخصّ العمرة والحج فقط
     */
    public function toBotArray(bool $withDistance = true): array
    {
        $out = array_filter([
            'city' => $this->city,
            'hotel' => $this->name,
            'rating' => $this->ratingLabel(),
            'location' => $this->location,
            'meals' => $this->meals,
        ], fn ($v) => $v !== null && $v !== '');

        if ($withDistance && $this->distance_haram) {
            $out['distance_from_haram'] = $this->distance_haram;
        }

        return $out;
    }
}
