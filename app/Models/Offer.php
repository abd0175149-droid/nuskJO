<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offer extends Model
{
    protected $fillable = [
        'title', 'category', 'description_client', 'price_jod', 'price_per',
        'includes', 'excludes', 'departure_date', 'return_date', 'valid_from', 'valid_to',
        'nights', 'hotel_name', 'hotel_rating', 'airline', 'agent_id', 'available_seats',
        'is_active', 'is_bot_visible', 'sort_order', 'cost_jod', 'notes_internal', 'created_by',
    ];

    protected $casts = [
        'includes' => 'array',
        'excludes' => 'array',
        'departure_date' => 'date',
        'return_date' => 'date',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'is_active' => 'boolean',
        'is_bot_visible' => 'boolean',
        'price_jod' => 'decimal:3',
        'cost_jod' => 'decimal:3',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
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

    /**
     * الحدّ الأمني: هذه وحدها الحقول التي تُعاد للبوت.
     * التكلفة (cost_jod) والملاحظات الداخلية لا تخرج من هنا إطلاقاً.
     */
    public function toBotArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'description' => $this->description_client,
            'price_jod' => (float) $this->price_jod,
            'price_per' => $this->price_per,
            'includes' => $this->includes ?: [],
            'excludes' => $this->excludes ?: [],
            'departure_date' => $this->departure_date?->toDateString(),
            'return_date' => $this->return_date?->toDateString(),
            'nights' => $this->nights,
            'hotel' => $this->hotel_name,
            'hotel_rating' => $this->hotel_rating,
            'airline' => $this->airline,
            'seats_left' => $this->available_seats,
        ];
    }
}
