<?php

namespace Database\Factories;

use App\Models\WaAdminNumber;
use App\Services\WhatsApp\AdminTools;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * رقم إدارة فعّال بكل صلاحيات المساعد الإداري.
 * الرقم يُخزَّن بالصيغة الموحّدة (PhoneNormalizer::canonical) لأن forPhone يطابق عليها.
 *
 * @extends Factory<WaAdminNumber>
 */
class WaAdminNumberFactory extends Factory
{
    protected $model = WaAdminNumber::class;

    public function definition(): array
    {
        return [
            'phone' => fake()->unique()->numerify('96279#######'),
            'name' => fake()->name(),
            'user_id' => null,
            'permissions' => array_keys(AdminTools::CATALOG),
            'is_active' => true,
        ];
    }

    /** الصلاحيات المذكورة فقط (مفاتيح AdminTools::CATALOG) */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn () => ['permissions' => array_values($permissions)]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
