<?php

namespace Database\Factories;

use App\Models\OfferGroup;
use App\Models\OfferOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * خيار فندقي بأسعار بيع للفرد {double: 250, triple: 220}.
 * offer_id يُشتق من المجموعة، لذا يأتي group_id قبله.
 *
 * @extends Factory<OfferOption>
 */
class OfferOptionFactory extends Factory
{
    protected $model = OfferOption::class;

    public function definition(): array
    {
        return [
            'group_id' => OfferGroup::factory(),
            'offer_id' => fn (array $attributes) => OfferGroup::findOrFail($attributes['group_id'])->offer_id,
            'label' => null,
            'includes_note' => 'يشمل الإفطار',
            'prices' => ['double' => 250, 'triple' => 220],
            'sell_flat' => 0,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
