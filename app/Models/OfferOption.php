<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * خيار داخل مجموعة اختيار — وهو الوحدة التي تحمل سعر البيع.
 *
 * خيار فندقي: أسعار بيع للفرد لكل سعة (prices) وإقامة أو أكثر (stays).
 * خيار غير فندقي (باص VIP): فرق بيع ثابت (sell_flat) وبنود تكلفة (costs).
 */
class OfferOption extends Model
{
    /** أنواع الغرف الثابتة — السعر دائماً «للفرد» */
    public const ROOM_TYPES = [
        'single' => 'مفردة',
        'double' => 'ثنائية',
        'triple' => 'ثلاثية',
        'quad'   => 'رباعية',
    ];

    protected $fillable = [
        'offer_id', 'group_id', 'label', 'includes_note', 'prices',
        'sell_flat', 'capacity', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'prices' => 'array',
        'sell_flat' => 'float',
        'capacity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(OfferGroup::class, 'group_id');
    }

    public function stays(): HasMany
    {
        return $this->hasMany(OfferStay::class, 'option_id')->orderBy('sort_order')->orderBy('id');
    }

    public function costs(): HasMany
    {
        return $this->hasMany(OfferCost::class, 'option_id')->orderBy('sort_order')->orderBy('id');
    }

    /** سعر بيع الفرد لسعة معيّنة، أو null إن لم تكن متاحة */
    public function sellFor(string $room): ?float
    {
        $v = $this->prices[$room] ?? null;

        return ($v !== null && $v !== '' && (float) $v > 0) ? round((float) $v, 3) : null;
    }

    /** الأسعار المتاحة فقط (غير الفارغة) مرتّبة بترتيب أنواع الغرف */
    public function availablePrices(): array
    {
        $out = [];
        foreach (self::ROOM_TYPES as $key => $label) {
            $v = $this->sellFor($key);
            if ($v !== null) {
                $out[$key] = ['label' => $label, 'price_per_person_jod' => $v];
            }
        }

        return $out;
    }

    /** أقل سعر للفرد في هذا الخيار */
    public function minPrice(): ?float
    {
        $p = array_column($this->availablePrices(), 'price_per_person_jod');
        return $p ? min($p) : null;
    }

    /** عنوان مختصر للخيار: اسمه إن وُجد، ثم أسماء فنادقه مسبوقةً بمدنها */
    public function label(): string
    {
        $hotels = $this->stays
            ->map(fn ($s) => $s->city ? "{$s->city}: {$s->name}" : $s->name)
            ->implode(' + ');

        if ($this->label && $hotels) {
            return "{$this->label} — {$hotels}";
        }

        return $this->label ?: ($hotels ?: '—');
    }

    /** ما يُعاد للبوت — لا يوجد في هذه الحقول أي حقل داخلي */
    public function toBotArray(bool $withDistance = true): array
    {
        return [
            'hotels' => $this->stays->map(fn ($s) => $s->toBotArray($withDistance))->values()->all(),
            'price_includes' => $this->includes_note,
            'prices_per_person_jod' => collect($this->availablePrices())
                ->mapWithKeys(fn ($v, $k) => [$v['label'] => $v['price_per_person_jod']])
                ->all(),
        ];
    }
}
