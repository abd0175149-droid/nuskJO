<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إقامة: فندق في مدينة، تحت خيار مسعّر.
 *
 * الوصف (city, name, rating, location, meals, distance_haram) والليالي يقرؤها البوت والبطاقة والفاتورة.
 * الوكيل والتكلفة لا يُحفظان على العرض: يُدخلان في كل فاتورة. أعمدة العقد القديمة
 * (agent_id, room_rates, currency) باقية في القاعدة بلا استخدام.
 */
class OfferStay extends Model
{
    protected $fillable = [
        'option_id', 'city', 'name', 'rating', 'rating_plus',
        'location', 'meals', 'distance_haram', 'sort_order',
        'agent_id', 'room_rates', 'nights', 'currency',
    ];

    protected $casts = [
        'rating_plus' => 'boolean',
        'room_rates' => 'array',
        'nights' => 'integer',
    ];

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
     * ما يُعاد للبوت — الوصف فقط.
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
