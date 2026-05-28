<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            // === إجازة سنوية ===
            ['name' => 'إجازة سنوية', 'code' => 'ANNUAL_SA', 'country' => 'SA', 'default_days' => 21, 'is_paid' => true, 'description' => 'نظام العمل السعودي: 21 يوم (30 بعد 5 سنوات)'],
            ['name' => 'إجازة سنوية', 'code' => 'ANNUAL_JO', 'country' => 'JO', 'default_days' => 14, 'is_paid' => true, 'description' => 'قانون العمل الأردني: 14 يوم (21 بعد 5 سنوات)'],

            // === إجازة مرضية ===
            ['name' => 'إجازة مرضية', 'code' => 'SICK_SA', 'country' => 'SA', 'default_days' => 120, 'is_paid' => true, 'requires_attachment' => true, 'description' => '30 يوم كامل + 60 يوم 75% + 30 يوم بدون'],
            ['name' => 'إجازة مرضية', 'code' => 'SICK_JO', 'country' => 'JO', 'default_days' => 14, 'is_paid' => true, 'requires_attachment' => true, 'description' => '14 يوم مدفوعة بتقرير طبي'],

            // === إجازة زواج ===
            ['name' => 'إجازة زواج', 'code' => 'MARRIAGE_SA', 'country' => 'SA', 'default_days' => 5, 'is_paid' => true, 'description' => '5 أيام مدفوعة'],
            ['name' => 'إجازة زواج', 'code' => 'MARRIAGE_JO', 'country' => 'JO', 'default_days' => 3, 'is_paid' => true, 'description' => '3 أيام مدفوعة'],

            // === إجازة وفاة ===
            ['name' => 'إجازة وفاة', 'code' => 'DEATH_SA', 'country' => 'SA', 'default_days' => 5, 'is_paid' => true, 'description' => '5 أيام مدفوعة'],
            ['name' => 'إجازة وفاة', 'code' => 'DEATH_JO', 'country' => 'JO', 'default_days' => 3, 'is_paid' => true, 'description' => '3 أيام مدفوعة'],

            // === إجازة أمومة ===
            ['name' => 'إجازة أمومة', 'code' => 'MATERNITY', 'country' => 'ALL', 'default_days' => 70, 'is_paid' => true, 'max_consecutive_days' => 70, 'description' => '70 يوم (10 أسابيع) مدفوعة'],

            // === إجازة بدون راتب ===
            ['name' => 'إجازة بدون راتب', 'code' => 'UNPAID', 'country' => 'ALL', 'default_days' => 30, 'is_paid' => false, 'description' => 'حسب الموافقة — تُخصم من الراتب'],

            // === إجازة حج (سعودي فقط) ===
            ['name' => 'إجازة حج', 'code' => 'HAJJ_SA', 'country' => 'SA', 'default_days' => 15, 'is_paid' => true, 'max_consecutive_days' => 15, 'description' => 'مرة واحدة خلال الخدمة'],
        ];

        foreach ($types as $type) {
            LeaveType::updateOrCreate(
                ['code' => $type['code']],
                array_merge([
                    'is_active' => true,
                    'requires_attachment' => false,
                    'max_consecutive_days' => null,
                ], $type)
            );
        }
    }
}
