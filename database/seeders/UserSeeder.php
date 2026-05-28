<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('slug', 'admin')->first();

        User::create([
            'name' => 'المدير العام',
            'email' => 'admin@nusuk-jo.com',
            'password' => bcrypt('password'),
            'role_id' => $adminRole?->id,
            'locale' => 'ar',
            'theme' => 'light',
            'is_active' => true,
        ]);
    }
}
