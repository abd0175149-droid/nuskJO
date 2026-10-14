<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('slug', 'admin')->first();

        // كلمة مرور عشوائية تُطبع مرة واحدة؛ لا قيمة ثابتة في الكود.
        $password = Str::password(16);

        $user = new User([
            'name' => 'المدير العام',
            'email' => 'admin@nusuk-jo.com',
            'password' => Hash::make($password),
            'locale' => 'ar',
            'theme' => 'light',
        ]);
        $user->forceFill(['role_id' => $adminRole?->id, 'is_active' => true])->save();

        $this->command?->warn("كلمة مرور المدير الأولية (غيّرها بعد الدخول): {$password}");
    }
}
