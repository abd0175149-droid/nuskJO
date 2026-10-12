<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مجموعة اختيار = قائمة منسدلة واحدة في فاتورة العرض.
 *
 * فنادق (stay): «فندق مكة» في نمط المدن، أو «الإقامة» في نمط الباقات.
 * غيرها (transport / flight / service): «النقل»، وخياراته بفرق بيع وبنود تكلفة.
 */
class OfferGroup extends Model
{
    public const KINDS = [
        'stay' => 'فنادق',
        'transport' => 'نقل',
        'flight' => 'طيران',
        'service' => 'خدمات',
    ];

    protected $fillable = ['offer_id', 'kind', 'label', 'city', 'nights', 'service_id', 'sort_order'];

    protected $casts = ['nights' => 'integer'];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(OfferOption::class, 'group_id')->orderBy('sort_order')->orderBy('id');
    }

    public function isStay(): bool
    {
        return $this->kind === 'stay';
    }

    public function activeOptions()
    {
        return $this->options->where('is_active', true)->values();
    }
}
