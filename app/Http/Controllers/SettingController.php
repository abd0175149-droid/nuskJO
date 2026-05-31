<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->groupBy('group_name');

        // قوالب الطباعة
        $financialTemplate = Setting::where('key', 'print_template_financial')->first();
        $accountingTemplate = Setting::where('key', 'print_template_accounting')->first();

        return Inertia::render('Settings/Index', [
            'title' => 'الإعدادات',
            'settings' => $settings,
            'templates' => [
                'financial' => $financialTemplate?->value ? Storage::url($financialTemplate->value) : null,
                'accounting' => $accountingTemplate?->value ? Storage::url($accountingTemplate->value) : null,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'nullable|string',
        ]);

        foreach ($validated['settings'] as $setting) {
            Setting::where('key', $setting['key'])->update(['value' => $setting['value']]);
        }

        return redirect()->back()->with('success', 'تم تحديث الإعدادات بنجاح');
    }

    /**
     * رفع قالب طباعة PDF
     */
    public function uploadTemplate(Request $request)
    {
        $request->validate([
            'template' => 'required|file|mimes:pdf|max:10240',
            'type' => 'required|in:financial,accounting',
        ]);

        $type = $request->input('type');
        $key = "print_template_{$type}";

        // حذف الملف القديم
        $old = Setting::where('key', $key)->first();
        if ($old?->value && Storage::exists($old->value)) {
            Storage::delete($old->value);
        }

        // رفع الملف الجديد
        $path = $request->file('template')->store('templates', 'public');

        // حفظ المسار في الإعدادات
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $path, 'group_name' => 'templates', 'label' => $type === 'financial' ? 'قالب مالي' : 'قالب محاسبي']
        );

        return redirect()->back()->with('success', 'تم رفع القالب بنجاح');
    }

    public function storeExchangeRate(Request $request)
    {
        $validated = $request->validate([
            'rate_date' => 'required|date',
            'sar_to_jod' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string|max:500',
        ]);

        $validated['jod_to_sar'] = round(1 / $validated['sar_to_jod'], 6);
        $validated['set_by'] = auth()->id();

        ExchangeRate::updateOrCreate(
            ['rate_date' => $validated['rate_date']],
            $validated
        );

        return redirect()->back()->with('success', 'تم تحديث سعر الصرف بنجاح');
    }

    /**
     * صفحة محرر تخطيط الطباعة
     */
    public function printLayout()
    {
        $financialTemplate = Setting::where('key', 'print_template_financial')->first();
        $financialUrl = $financialTemplate?->value ? Storage::url($financialTemplate->value) : null;

        $accountingTemplate = Setting::where('key', 'print_template_accounting')->first();
        $accountingUrl = $accountingTemplate?->value ? Storage::url($accountingTemplate->value) : null;

        // تحميل التخطيطات المحفوظة
        $layouts = [];
        $allTypes = ['invoice', 'transfer', 'receipt', 'statement', 'chart', 'trial_balance', 'profit_loss', 'balance_sheet'];
        foreach ($allTypes as $type) {
            $setting = Setting::where('key', "print_layout_{$type}")->first();
            $layouts[$type] = $setting?->value ? json_decode($setting->value, true) : null;
        }

        return Inertia::render('Settings/PrintLayout', [
            'title' => 'محرر تخطيط الطباعة',
            'templateUrl' => $financialUrl,
            'accountingTemplateUrl' => $accountingUrl,
            'layouts' => $layouts,
        ]);
    }

    /**
     * حفظ تخطيط الطباعة
     */
    public function savePrintLayout(Request $request)
    {
        $request->validate([
            'type' => 'required|in:invoice,transfer,receipt,statement,chart,trial_balance,profit_loss,balance_sheet',
            'layout' => 'required|array',
        ]);

        $key = "print_layout_{$request->type}";

        Setting::updateOrCreate(
            ['key' => $key],
            [
                'value' => json_encode($request->layout, JSON_UNESCAPED_UNICODE),
                'group_name' => 'printing',
                'label' => "تخطيط طباعة {$request->type}",
            ]
        );

        return redirect()->back()->with('success', 'تم حفظ التخطيط بنجاح');
    }
}
