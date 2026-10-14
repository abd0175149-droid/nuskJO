<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferCost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * بند تكلفة غير فندقي (قديم على العرض، لا يقرؤه البوت).
 *
 * @extends Factory<OfferCost>
 */
class OfferCostFactory extends Factory
{
    protected $model = OfferCost::class;

    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory()->bare(),
            'option_id' => null,
            'kind' => 'service',
            'label' => 'زيارات',
            'agent_id' => null,
            'basis' => 'per_person',
            'qty' => 1,
            'unit_cost' => 10,
            'currency' => 'JOD',
            'sort_order' => 0,
        ];
    }
}
