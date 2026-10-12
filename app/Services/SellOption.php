<?php

namespace App\Services;

use App\Models\OfferOption;
use Illuminate\Support\Collection;

/**
 * خيار بيع كما يراه العميل — إسقاط من سجل العرض لا يُخزَّن.
 *
 * نمط الباقات: خيار واحد من مجموعة «الإقامة» كما هو.
 * نمط المدن: تركيبة من خيار في كل مجموعة فنادق (مكة × المدينة)، والسعر مجموعها.
 *
 * يحمل نفس شكل OfferOption الذي تقرؤه بطاقة العرض (prices, stays, includes_note)
 * ونفس حمولة البوت، ولا يحمل أي حقل تكلفة.
 */
class SellOption
{
    /**
     * @param string $ref مرجع ثابت يعيده البوت في confirm_booking (معرّفات الخيارات بـ «+»)
     * @param array $sel [group_id => option_id] لكل مجموعات العرض، مع الخيار الافتراضي لغير الفندقية
     * @param array $prices [room => سعر الفرد] — null للسعة غير المتاحة
     * @param Collection $stays إقامات الخيار، والمدينة فيها مدينة المجموعة في نمط المدن
     */
    public function __construct(
        public string $ref,
        public array $sel,
        public array $prices,
        public Collection $stays,
        public ?string $includes_note = null,
        public ?string $label = null,
    ) {
    }

    public function availablePrices(): array
    {
        $out = [];
        foreach (OfferOption::ROOM_TYPES as $key => $label) {
            $v = $this->prices[$key] ?? null;
            if ($v !== null && (float) $v > 0) {
                $out[$key] = ['label' => $label, 'price_per_person_jod' => round((float) $v, 3)];
            }
        }

        return $out;
    }

    public function minPrice(): ?float
    {
        $p = array_column($this->availablePrices(), 'price_per_person_jod');

        return $p ? min($p) : null;
    }

    /** نفس شكل OfferOption::toBotArray() ومعه مرجع الخيار */
    public function toBotArray(bool $withDistance = true): array
    {
        return [
            'ref' => $this->ref,
            'hotels' => $this->stays->map(fn ($s) => $s->toBotArray($withDistance))->values()->all(),
            'price_includes' => $this->includes_note,
            'prices_per_person_jod' => collect($this->availablePrices())
                ->mapWithKeys(fn ($v) => [$v['label'] => $v['price_per_person_jod']])
                ->all(),
        ];
    }
}
