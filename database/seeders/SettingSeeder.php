<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // بيانات الشركة
            ['key' => 'company_name_ar', 'value' => 'شركة نسك', 'group_name' => 'company', 'type' => 'string', 'description' => 'اسم الشركة بالعربي'],
            ['key' => 'company_name_en', 'value' => 'NUSUK Company', 'group_name' => 'company', 'type' => 'string', 'description' => 'اسم الشركة بالإنجليزي'],
            ['key' => 'company_phone', 'value' => '', 'group_name' => 'company', 'type' => 'string', 'description' => 'هاتف الشركة'],
            ['key' => 'company_address', 'value' => '', 'group_name' => 'company', 'type' => 'string', 'description' => 'عنوان الشركة'],
            ['key' => 'company_logo', 'value' => '', 'group_name' => 'company', 'type' => 'image', 'description' => 'شعار الشركة'],
            ['key' => 'tax_number', 'value' => '', 'group_name' => 'company', 'type' => 'string', 'description' => 'الرقم الضريبي'],
            // البادئات
            ['key' => 'invoice_prefix', 'value' => 'INV', 'group_name' => 'general', 'type' => 'string', 'description' => 'بادئة رقم الفاتورة'],
            ['key' => 'receipt_prefix', 'value' => 'RCP', 'group_name' => 'general', 'type' => 'string', 'description' => 'بادئة رقم سند القبض'],
            ['key' => 'transfer_prefix', 'value' => 'TRF', 'group_name' => 'general', 'type' => 'string', 'description' => 'بادئة رقم الحوالة'],

            ['key' => 'expense_prefix', 'value' => 'EXP', 'group_name' => 'general', 'type' => 'string', 'description' => 'بادئة رقم المصروف'],
        ];

        foreach ($settings as $setting) {
            Setting::create($setting);
        }
    }
}
