<?php

namespace Database\Factories;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** مستخدم بدور المدير (يُنشأ الدور إن لم يوجد). */
    public function admin(): static
    {
        return $this->state(fn () => [
            'role_id' => Role::firstOrCreate(['slug' => 'admin'], ['name' => 'مدير النظام'])->id,
        ]);
    }

    /**
     * مستخدم بدور يحمل الصلاحيات المذكورة فقط.
     * تُنشأ الصلاحيات بـ firstOrCreate، دون تشغيل RolePermissionSeeder.
     */
    public function withPermissions(array $slugs, string $role = 'sales'): static
    {
        return $this->state(function () use ($slugs, $role) {
            $roleModel = Role::firstOrCreate(['slug' => $role], ['name' => $role]);
            $ids = collect($slugs)->map(fn (string $slug) => Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'module' => explode('.', $slug)[0]],
            )->id);
            $roleModel->permissions()->syncWithoutDetaching($ids);

            return ['role_id' => $roleModel->id];
        });
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
