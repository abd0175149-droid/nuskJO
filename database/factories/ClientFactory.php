<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'code' => 'C-'.fake()->unique()->numerify('#####'),
            'phone' => fake()->numerify('96279#######'),
            'created_by' => null,
        ];
    }
}
