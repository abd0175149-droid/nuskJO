<?php

namespace App\Services;

use App\Models\Setting;

/**
 * مصدر واحد لهوية الشركة: الإعدادات.
 * يقرأ منه البوت (يُحقن في موجّهه) والصفحات القانونية — فتحديث الإعدادات يحدّث الجميع.
 */
class CompanyInfo
{
    public static function all(): array
    {
        return [
            'name'     => Setting::get('company_name_ar') ?: 'شركة نُسك الذهبية للسياحة والسفر',
            'name_en'  => Setting::get('company_name_en') ?: 'NUSUK Gold Travel',
            'phone'    => Setting::get('company_phone'),
            'email'    => Setting::get('company_email'),
            'address'  => Setting::get('company_address'),
            'hours'    => Setting::get('company_hours'),
            'facebook' => Setting::get('company_facebook'),
            'tax'      => Setting::get('tax_number'),
        ];
    }

    /** كتلة تُحقن في موجّه البوت — لا يكتبها المالك يدوياً في قاعدة المعرفة */
    public static function botBlock(): string
    {
        $c = self::all();

        $lines = ['=== بيانات الشركة (استخدمها عند السؤال عنها) ==='];
        $lines[] = 'الاسم: ' . $c['name'];
        if ($c['phone'])    $lines[] = 'رقم التواصل: ' . $c['phone'];
        if ($c['address'])  $lines[] = 'العنوان: ' . $c['address'];
        if ($c['hours'])    $lines[] = 'مواعيد الدوام: ' . $c['hours'];
        if ($c['facebook']) $lines[] = 'صفحة فيسبوك: ' . $c['facebook'];
        if ($c['email'])    $lines[] = 'البريد الإلكتروني: ' . $c['email'];
        $lines[] = 'لا تذكر أي بيان تواصل غير المذكور هنا، ولا تخترع عنواناً أو رقماً.';

        return implode("\n", $lines);
    }
}
