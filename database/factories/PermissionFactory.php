<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Permission> */
class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    public function definition(): array
    {
        $slug = 'test.'.fake()->unique()->lexify('??????');

        return ['name' => $slug, 'slug' => $slug, 'module' => 'test', 'description' => null];
    }
}
