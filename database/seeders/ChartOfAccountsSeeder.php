<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            // === 1000 الأصول ===
            ['code' => '1000', 'name' => 'الأصول', 'type' => 'asset', 'parent_id' => null, 'is_system' => true, 'currency' => 'JOD'],
            ['code' => '1100', 'name' => 'الأصول المتداولة', 'type' => 'asset', 'parent_code' => '1000', 'is_system' => true, 'currency' => 'JOD'],
            ['code' => '1101', 'name' => 'الصندوق (النقدية)', 'type' => 'asset', 'parent_code' => '1100', 'is_system' => true, 'currency' => 'JOD', 'description' => 'المبالغ النقدية الكاش'],
            ['code' => '1102', 'name' => 'البنك', 'type' => 'asset', 'parent_code' => '1100', 'is_system' => true, 'currency' => 'JOD', 'description' => 'الأرصدة البنكية'],
            ['code' => '1103', 'name' => 'شيكات تحت التحصيل', 'type' => 'asset', 'parent_code' => '1100', 'is_system' => true, 'currency' => 'JOD', 'description' => 'شيكات مستلمة لم تُصرف بعد'],
            ['code' => '1200', 'name' => 'ذمم العملاء', 'type' => 'asset', 'parent_code' => '1100', 'is_system' => true, 'currency' => 'JOD', 'description' => 'المبالغ المستحقة على العملاء'],
            ['code' => '1300', 'name' => 'ذمم الموظفين (سلف)', 'type' => 'asset', 'parent_code' => '1100', 'is_system' => true, 'currency' => 'JOD', 'description' => 'سلف الموظفين'],

            // === 2000 الالتزامات ===
            ['code' => '2000', 'name' => 'الالتزامات', 'type' => 'liability', 'parent_id' => null, 'is_system' => true, 'currency' => 'JOD'],
            ['code' => '2100', 'name' => 'التزامات قصيرة الأجل', 'type' => 'liability', 'parent_code' => '2000', 'is_system' => true, 'currency' => 'JOD'],
            ['code' => '2101', 'name' => 'دائنون متنوعون', 'type' => 'liability', 'parent_code' => '2000', 'is_system' => true, 'currency' => 'JOD'],
            ['code' => '2110', 'name' => 'الوكلاء', 'type' => 'liability', 'parent_code' => '2101', 'is_system' => true, 'currency' => 'JOD', 'description' => 'حسابات الوكلاء — تُنشأ فرعياً تلقائياً عند إضافة وكيل جديد'],

            // === 3000 حقوق الملكية ===
            ['code' => '3000', 'name' => 'حقوق الملكية', 'type' => 'equity', 'parent_id' => null, 'is_system' => true, 'currency' => 'JOD'],
            ['code' => '3001', 'name' => 'رأس المال', 'type' => 'equity', 'parent_code' => '3000', 'is_system' => true, 'currency' => 'JOD'],
            ['code' => '3002', 'name' => 'الأرباح المحتجزة', 'type' => 'equity', 'parent_code' => '3000', 'is_system' => true, 'currency' => 'JOD'],

            // === 4000 الإيرادات ===
            ['code' => '4000', 'name' => 'الإيرادات', 'type' => 'revenue', 'parent_id' => null, 'is_system' => true, 'currency' => 'JOD'],
            ['code' => '4001', 'name' => 'إيرادات الخدمات', 'type' => 'revenue', 'parent_code' => '4000', 'is_system' => true, 'currency' => 'JOD', 'description' => 'أرباح الفواتير من الفرق بين التكلفة وسعر البيع'],
            ['code' => '4002', 'name' => 'إيرادات فروقات الصرف', 'type' => 'revenue', 'parent_code' => '4000', 'is_system' => true, 'currency' => 'JOD'],
            ['code' => '4003', 'name' => 'إيرادات أخرى', 'type' => 'revenue', 'parent_code' => '4000', 'is_system' => true, 'currency' => 'JOD'],

            // === 5000 المصروفات ===
            ['code' => '5000', 'name' => 'المصروفات', 'type' => 'expense', 'parent_id' => null, 'is_system' => true, 'currency' => 'JOD'],
            ['code' => '5100', 'name' => 'مصاريف تشغيلية', 'type' => 'expense', 'parent_code' => '5000', 'is_system' => true, 'currency' => 'JOD', 'description' => 'المصاريف اليومية والتشغيلية'],
            ['code' => '5200', 'name' => 'مصاريف المخالفات', 'type' => 'expense', 'parent_code' => '5000', 'is_system' => true, 'currency' => 'JOD', 'description' => 'تكلفة مخالفات الوكلاء (محولة بالدينار)'],
            ['code' => '5300', 'name' => 'مصاريف أخرى', 'type' => 'expense', 'parent_code' => '5000', 'is_system' => true, 'currency' => 'JOD'],
            ['code' => '5400', 'name' => 'مصاريف الرواتب', 'type' => 'expense', 'parent_code' => '5000', 'is_system' => true, 'currency' => 'JOD', 'description' => 'رواتب الموظفين'],
            ['code' => '5410', 'name' => 'مصاريف البدلات', 'type' => 'expense', 'parent_code' => '5000', 'is_system' => true, 'currency' => 'JOD', 'description' => 'بدلات سكن ومواصلات وأخرى'],
            ['code' => '5420', 'name' => 'مصاريف العمل الإضافي', 'type' => 'expense', 'parent_code' => '5000', 'is_system' => true, 'currency' => 'JOD', 'description' => 'ساعات العمل الإضافي للموظفين'],
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
