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
        $settings = Setting::whereNotIn('key', [...Setting::INTERNAL_KEYS, 'reconcile_signature'])->get()->groupBy('group_name');

        // قوالب الطباعة
        $financialTemplate = Setting::where('key', 'print_template_financial')->first();
        $accountingTemplate = Setting::where('key', 'print_template_accounting')->first();

        return Inertia::render('Settings/Index', [
            'title' => 'الإعدادات',
            'settings' => $settings,
            'editableKeys' => array_keys(\App\Http\Requests\UpdateSettingsRequest::EDITABLE),
            'templates' => [
                'financial' => $financialTemplate?->value ? Storage::url($financialTemplate->value) : null,
                'accounting' => $accountingTemplate?->value ? Storage::url($accountingTemplate->value) : null,
            ],
            // شارة «آخر نسخة» لمن يرى صفحة النسخ الاحتياطي
            'lastBackup' => auth()->user()->can('backups.view') && \Illuminate\Support\Facades\Schema::hasTable('backup_runs')
                ? ['at' => ($at = \App\Models\BackupRun::where('status', 'ok')->max('finished_at')) ? \Illuminate\Support\Carbon::parse($at)->toIso8601String() : null]
                : null,
            // هوية بطاقات العروض
            'brand' => [
                'logo' => ($v = Setting::get('company_logo')) ? Storage::url($v) : null,
                'hero' => ($h = Setting::get('card_hero_path')) ? Storage::url($h) : null,
                'letterhead' => ($l = Setting::get('card_letterhead_path')) ? Storage::url($l) : null,
            ],
        ]);
    }

    public function update(\App\Http\Requests\UpdateSettingsRequest $request)
    {
        // المفاتيح المسموحة فقط (UpdateSettingsRequest::EDITABLE)، وعلى الصفوف الموجودة وحدها
        $changed = [];
        foreach ($request->validated()['values'] ?? [] as $key => $value) {
            $old = Setting::where('key', $key)->value('value');
            $new = $value === null ? null : (string) $value;
            Setting::where('key', $key)->update(['value' => $new]);
            if ($old !== $new) {
                // مقصوصة، ومفاتيح الأسرار تُحجب في AuditRedactor
                $changed[$key] = ['before' => $old === null ? null : mb_substr($old, 0, 200), 'after' => $new === null ? null : mb_substr($new, 0, 200)];
            }
        }
        if ($changed) {
            \App\Models\AuditLog::safe(\App\Enums\AuditAction::SettingsUpdate, 'Setting', null, null, ['changed' => $changed]);
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
        if (self::ownedUpload($old?->value, 'templates/')) {
            Storage::disk('public')->delete($old->value);
        }

        // رفع الملف الجديد
        $path = $request->file('template')->store('templates', 'public');

        // حفظ المسار في الإعدادات
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $path, 'group_name' => 'templates', 'label' => $type === 'financial' ? 'قالب مالي' : 'قالب محاسبي']
        );
        \App\Models\AuditLog::safe(\App\Enums\AuditAction::SettingsUpdate, 'Setting', null, $key, ['key' => $key]);

        return redirect()->back()->with('success', 'تم رفع القالب بنجاح');
    }

    /**
     * رفع هوية الشركة البصرية المستخدمة في بطاقات العروض:
     * الشعار (PNG بخلفية شفافة) وصورة الترويسة الافتراضية.
     */
    public function uploadBrandAsset(Request $request)
    {
        $request->validate([
            'asset' => 'required|image|mimes:png,jpg,jpeg,webp,svg|max:5120',
            'type' => 'required|in:logo,hero,letterhead',
        ]);

        $type = $request->input('type');
        // company_logo مُهيّأ مسبقاً في البذور (type=image) ولم يكن يُقرأ من أي مكان
        $key = match ($type) {
            'logo' => 'company_logo',
            'letterhead' => 'card_letterhead_path',
            default => 'card_hero_path',
        };

        $old = Setting::where('key', $key)->first();
        if (self::ownedUpload($old?->value, 'brand/')) {
            Storage::disk('public')->delete($old->value);
        }

        $path = $request->file('asset')->store('brand', 'public');

        Setting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $path,
                'group_name' => 'company',
                'type' => 'image',   // تُدار من قسم الهوية لا كحقل نصّي
                'description' => match ($type) {
                    'logo' => 'شعار الشركة',
                    'letterhead' => 'ورقة الشركة الرسمية',
                    default => 'ترويسة بطاقات العروض',
                },
            ]
        );

        // نسب ترويسة الورقة وتذييلها — تختلف من ورقة لأخرى فتُضبط يدوياً
        if ($type === 'letterhead') {
            foreach ([
                'card_letterhead_top' => ['21', 'نسبة ترويسة الورقة من ارتفاع الصفحة %'],
                'card_letterhead_bottom' => ['15', 'نسبة تذييل الورقة من ارتفاع الصفحة %'],
            ] as $k => [$default, $label]) {
                Setting::firstOrCreate(
                    ['key' => $k],
                    ['value' => $default, 'group_name' => 'company', 'type' => 'number', 'description' => $label]
                );
            }
        }

        \App\Models\AuditLog::safe(\App\Enums\AuditAction::SettingsUpdate, 'Setting', null, "brand_{$type}", ['key' => "brand_{$type}"]);

        return redirect()->back()->with('success', match ($type) {
            'logo' => 'تم رفع الشعار',
            'letterhead' => 'تم رفع ورقة الشركة — اضبط النسب ثم أعد توليد البطاقات',
            default => 'تم رفع صورة الترويسة',
        });
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

        // سعر الصرف يدخل حساب كل فاتورة: صف تحذير في المعاملة نفسها
        \Illuminate\Support\Facades\DB::transaction(function () use ($validated) {
            // whereDate: العمود يُخزَّن بوقت (00:00:00)، فمطابقة النص وحده لا تجد سعر اليوم وتصطدم بالقيد الفريد
            $rate = ExchangeRate::whereDate('rate_date', $validated['rate_date'])->first();
            $before = $rate?->sar_to_jod;
            if ($rate) {
                $rate->update($validated);
            } else {
                $rate = ExchangeRate::create($validated);
            }
            \App\Models\AuditLog::log(\App\Enums\AuditAction::ExchangeRateSet, 'exchange_rate', $rate->id, (string) $validated['rate_date'],
                ['date' => (string) $validated['rate_date'], 'from' => $before === null ? null : (float) $before, 'to' => (float) $validated['sar_to_jod']]);
        });

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
        \App\Models\AuditLog::safe(\App\Enums\AuditAction::SettingsUpdate, 'Setting', null, $key, ['key' => $key]);

        return redirect()->back()->with('success', 'تم حفظ التخطيط بنجاح');
    }

    /** مسار مخزّن رفعناه نحن: داخل مجلده المتوقع، بلا صعود، وموجود. غير ذلك لا يُحذف أبداً. */
    private static function ownedUpload(?string $path, string $prefix): bool
    {
        return $path
            && str_starts_with($path, $prefix)
            && !str_contains($path, '..')
            && !str_contains($path, '\\')
            && Storage::disk('public')->exists($path);
    }
}
