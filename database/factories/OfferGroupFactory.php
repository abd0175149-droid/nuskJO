<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * مجموعة اختيار؛ الافتراضي مجموعة الإقامة في نمط الباقات.
 *
 * @extends Factory<OfferGroup>
 */
class OfferGroupFactory extends Factory
{
    protected $model = OfferGroup::class;

    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory()->bare(),
            'kind' => 'stay',
            'label' => 'الإقامة',
            'city' => null,
            'nights' => null,
            'sort_order' => 0,
        ];
    }

    /** مجموعة غير فندقية (النقل مثلاً) */
    public function kind(string $kind, string $label): static
    {
        return $this->state(fn () => ['kind' => $kind, 'label' => $label]);
    }
}
