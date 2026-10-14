<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferCost;
use App\Models\OfferGroup;
use App\Models\OfferOption;
use App\Models\OfferStay;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * عرض عمرة ظاهر للبوت بنمط الباقات، ومعه مجموعة إقامة واحدة:
 * خيار واحد بأسعار للفرد {double: 250, triple: 220} وإقامة واحدة.
 *
 * - bare(): العرض وحده بلا مجموعات. تُستدعى أولاً لأنها تُسقط ما قبلها من afterCreating.
 * - withCanaryInternals(): قيم داخلية لا تتكرر في أي مكان آخر (تكلفة، وكيل، أسعار شراء، مقاعد)،
 *   يبحث عنها اختبار التسريب في كل ما يصل البوت والنموذج.
 *
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    protected $model = Offer::class;

    /** القيم الداخلية في withCanaryInternals() — ولا واحدة منها يجوز أن تصل البوت */
    public const CANARY_ROOM_RATE = 777.777;

    public const CANARY_AGENT_ID = 4242;

    public const CANARY_UNIT_COST = 888.888;

    public const CANARY_COST_LABEL = 'CANARY-COST';

    public const CANARY_TOTAL_SEATS = 9137;

    public function definition(): array
    {
        return [
            'title' => 'عمرة '.fake()->randomElement(['الربيع', 'رمضان', 'المولد', 'الشتاء']),
            'category' => 'umrah',
            'route_mode' => 'makkah_only',
            'description_client' => 'عمرة بالباص مع إقامة قريبة من الحرم.',
            'includes' => ['التأشيرة', 'النقل'],
            'excludes' => ['الوجبات'],
            'nights' => 7,
            'is_active' => true,
            'is_bot_visible' => true,
            'is_ready' => false,
            'pricing_mode' => 'bundle',
            'base_sell_pp' => 0,
            'sort_order' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Offer $offer) {
            $group = OfferGroup::factory()->create(['offer_id' => $offer->id]);
            $option = OfferOption::factory()->create(['offer_id' => $offer->id, 'group_id' => $group->id]);
            OfferStay::factory()->create(['option_id' => $option->id]);
        });
    }

    /** العرض وحده بلا مجموعات ولا خيارات */
    public function bare(): static
    {
        return $this->newInstance(['afterCreating' => new Collection]);
    }

    /** عرض جاهز بتاريخ رحلة قادم ومقاعد (يُبقي total_seats إن ضُبط قبله) */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_ready' => true,
            'trip_date' => now()->addDays(30)->toDateString(),
            'return_date' => now()->addDays(37)->toDateString(),
            'travel_mode' => 'land',
            'destination' => 'مكة المكرمة',
            'total_seats' => $attributes['total_seats'] ?? 45,
        ]);
    }

    /**
     * يملأ الحقول الداخلية بقيم canary:
     * offer_stays.room_rates={double: 777.777} وoffer_stays.agent_id=4242،
     * وبند offer_costs بـ unit_cost=888.888 وlabel='CANARY-COST'، وtotal_seats=9137.
     */
    public function withCanaryInternals(): static
    {
        return $this->state(fn () => ['total_seats' => self::CANARY_TOTAL_SEATS])
            ->afterCreating(function (Offer $offer) {
                $optionIds = OfferOption::where('offer_id', $offer->id)->pluck('id');

                OfferStay::whereIn('option_id', $optionIds)->get()->each->update([
                    'room_rates' => ['double' => self::CANARY_ROOM_RATE],
                    'agent_id' => self::CANARY_AGENT_ID,
                ]);

                OfferCost::factory()->create([
                    'offer_id' => $offer->id,
                    'option_id' => $optionIds->first(),
                    'label' => self::CANARY_COST_LABEL,
                    'unit_cost' => self::CANARY_UNIT_COST,
                ]);
            });
    }
}
