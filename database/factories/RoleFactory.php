<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Role> */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $slug = 'role_'.fake()->unique()->lexify('??????');

        return ['name' => $slug, 'slug' => $slug, 'description' => null];
    }
}
