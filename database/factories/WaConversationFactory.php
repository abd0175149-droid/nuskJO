<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\WaConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WaConversation> */
class WaConversationFactory extends Factory
{
    protected $model = WaConversation::class;

    public function definition(): array
    {
        $phone = fake()->unique()->numerify('96279#######');

        return [
            'phone' => $phone,
            'wa_phone' => $phone,
            'display_name' => fake()->name(),
            'bot_enabled' => true,
        ];
    }

    public function windowOpen(): static
    {
        return $this->state(fn () => ['last_inbound_at' => now()->subHour()]);
    }

    public function windowClosed(): static
    {
        return $this->state(fn () => ['last_inbound_at' => now()->subDays(2)]);
    }

    public function botOff(): static
    {
        return $this->state(fn () => ['bot_enabled' => false]);
    }

    /** البوت موقوف مؤقتاً لهذه المحادثة (كما بعد ردّ موظف) */
    public function paused(int $minutes = 30): static
    {
        return $this->state(fn () => ['bot_paused_until' => now()->addMinutes($minutes)]);
    }

    public function linkedTo(Client $client): static
    {
        return $this->state(fn () => ['client_id' => $client->id]);
    }
}
