<?php

namespace Database\Factories;

use App\Models\OfferOption;
use App\Models\OfferStay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * إقامة: وصف الفندق فقط. أعمدة العقد القديمة (agent_id وroom_rates) فارغة؛
 * تملؤها OfferFactory::withCanaryInternals() لاختبار التسريب.
 *
 * @extends Factory<OfferStay>
 */
class OfferStayFactory extends Factory
{
    protected $model = OfferStay::class;

    public function definition(): array
    {
        return [
            'option_id' => OfferOption::factory(),
            'city' => 'مكة',
            'name' => fake()->randomElement(['فندق الصفوة', 'فندق دار الإيمان', 'فندق المروة']),
            'rating' => 4,
            'rating_plus' => false,
            'location' => 'العزيزية',
            'meals' => 'إفطار',
            'distance_haram' => '700 م',
            'nights' => 7,
            'sort_order' => 0,
        ];
    }
}
