<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class HRAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            // ذمم الموظفين (سلف) — تحت الأصول المتداولة 1100
            ['code' => '1300', 'name' => 'ذمم الموظفين (سلف)', 'type' => 'asset', 'parent_code' => '1100', 'is_system' => true, 'currency' => 'JOD', 'description' => 'المبالغ المستحقة على الموظفين (سلف)'],

            // مصاريف الرواتب — تحت المصروفات 5000
            ['code' => '5400', 'name' => 'مصاريف الرواتب', 'type' => 'expense', 'parent_code' => '5000', 'is_system' => true, 'currency' => 'JOD', 'description' => 'إجمالي مصاريف الرواتب والأجور'],
            ['code' => '5410', 'name' => 'مصاريف البدلات', 'type' => 'expense', 'parent_code' => '5400', 'is_system' => true, 'currency' => 'JOD', 'description' => 'بدلات السكن والنقل والأخرى'],
            ['code' => '5420', 'name' => 'مصاريف العمل الإضافي', 'type' => 'expense', 'parent_code' => '5400', 'is_system' => true, 'currency' => 'JOD', 'description' => 'تكلفة ساعات العمل الإضافي'],
        ];

        foreach ($accounts as $data) {
            $parentCode = $data['parent_code'] ?? null;
            unset($data['parent_code']);

            if ($parentCode) {
                $parent = Account::where('code', $parentCode)->first();
                $data['parent_id'] = $parent?->id;
            }

            Account::updateOrCreate(
                ['code' => $data['code']],
                $data
            );
        }
    }
}
