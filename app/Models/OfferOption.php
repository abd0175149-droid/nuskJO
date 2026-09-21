<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * خيار مسعّر داخل العرض — وهو الوحدة التي تحمل السعر.
 *
 * كان اسمه offer_hotels حين كان السعر ملك الفندق. مع عمرة «مكة والمدينة»
 * صار الخيار الواحد يضمّ فندقين بسعر واحد لهما معاً، فانفصل السعر عن الفندق.
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

    protected $fillable = ['offer_id', 'includes_note', 'prices', 'sort_order'];

    protected $casts = ['prices' => 'array'];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(OfferStay::class, 'option_id')->orderBy('sort_order')->orderBy('id');
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

    /** أقل سعر للفرد في هذا الخيار */
    public function minPrice(): ?float
    {
        $p = array_column($this->availablePrices(), 'price_per_person_jod');
        return $p ? min($p) : null;
    }

    /** عنوان مختصر للخيار: أسماء فنادقه مسبوقةً بمدنها إن وُجدت */
    public function label(): string
    {
        return $this->stays
            ->map(fn ($s) => $s->city ? "{$s->city}: {$s->name}" : $s->name)
            ->implode(' + ') ?: '—';
    }

    /** ما يُعاد للبوت — لا يوجد في هذه الجداول أي حقل داخلي */
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
