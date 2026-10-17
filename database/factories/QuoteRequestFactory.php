<?php

namespace Database\Factories;

use App\Models\QuoteRequest;
use App\Models\WaConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * طلب تسعير جديد.
 * رقم الطلب بصيغة QR-TEST-##### كي لا يتداخل مع تسلسل QuoteRequest::nextNumber() (QR-{اليوم}-####).
 *
 * @extends Factory<QuoteRequest>
 */
class QuoteRequestFactory extends Factory
{
    protected $model = QuoteRequest::class;

    public function definition(): array
    {
        return [
            'request_number' => fake()->unique()->numerify('QR-TEST-#####'),
            'conversation_id' => null,
            'client_id' => null,
            'offer_id' => null,
            'phone' => fake()->numerify('96279#######'),
            'customer_name' => fake()->name(),
            'type' => 'flight',
            'route_from' => 'AMM',
            'route_to' => 'JED',
            'depart_date' => now()->addDays(20)->toDateString(),
            'pax_adults' => 1,
            'pax_children' => 0,
            'pax_infants' => 0,
            'details' => fake()->sentence(),
            'status' => 'new',
        ];
    }

    public function forConversation(WaConversation $conv): static
    {
        return $this->state(fn () => [
            'conversation_id' => $conv->id,
            'client_id' => $conv->client_id,
            'phone' => $conv->phone,
            'customer_name' => $conv->display_name,
        ]);
    }
}
