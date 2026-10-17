<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WaMessage> */
class WaMessageFactory extends Factory
{
    protected $model = WaMessage::class;

    public function definition(): array
    {
        return [
            'conversation_id' => WaConversation::factory(),
            'wamid' => 'wamid.TEST.'.Str::uuid(),
            'direction' => 'in',
            'source' => 'customer',
            'msg_type' => 'text',
            'body' => fake()->sentence(),
            'status' => 'received',
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn () => ['direction' => 'in', 'source' => 'customer', 'status' => 'received']);
    }

    public function outbound(): static
    {
        return $this->state(fn () => ['direction' => 'out', 'source' => 'bot', 'status' => 'sent']);
    }

    public function fromStaff(User $user): static
    {
        return $this->state(fn () => ['direction' => 'out', 'source' => 'staff', 'status' => 'sent', 'staff_id' => $user->id]);
    }
}
