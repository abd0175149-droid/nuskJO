<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند تكلفة غير فندقي: تأشيرة، طيران، نقل، زيارات…
 *
 * option_id فارغ = بند ثابت يدخل كل فاتورة على العرض.
 * per_person: العدد × تكلفة الوحدة لكل مسافر.
 * per_group:  (العدد × تكلفة الوحدة) ÷ مقاعد العرض لكل مقعد يُباع، والباقي عند الإقفال.
 */
class OfferCost extends Model
{
    use HasFactory;

    public const KINDS = [
        'visa' => 'تأشيرة',
        'flight' => 'طيران',
        'transport' => 'نقل',
        'service' => 'خدمات',
    ];

    public const BASES = [
        'per_person' => 'للفرد',
        'per_group' => 'مقطوع للرحلة',
    ];

    protected $fillable = [
        'offer_id', 'option_id', 'kind', 'label', 'agent_id', 'service_id',
        'basis', 'qty', 'unit_cost', 'currency', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'float',
        'unit_cost' => 'float',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(OfferOption::class, 'option_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
