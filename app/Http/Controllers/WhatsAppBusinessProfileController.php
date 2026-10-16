<?php

namespace App\Http\Controllers;

use App\Models\WaBotSetting;
use App\Services\WhatsApp\BusinessProfileSync;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ملف واتساب للأعمال (E31) في صفحة إعدادات الواتساب: القراءة والمقارنة ببيانات الشركة، والمزامنة بتأكيد،
 * والصورة الاختيارية. كله بصلاحية whatsapp.settings، والقيم المرسلة لميتا تُحسب في الخادم وحده.
 * اسم العرض للقراءة فقط: لا مسار يغيّره (WhatsApp Manager وحده).
 */
class WhatsAppBusinessProfileController extends Controller
{
    /** GET api/whatsapp/business-profile[?refresh=1]: كاش 10 دقائق */
    public function show(Request $request): JsonResponse
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        return response()->json(BusinessProfileSync::overview($request->boolean('refresh')) + [
            'limits' => BusinessProfileSync::LIMITS,
            'labels' => BusinessProfileSync::LABELS,
            'can' => [
                'sync' => FeatureFlags::on('business_profile_sync'),
                'photo' => FeatureFlags::on('business_profile_photo'),
                'app_id' => filled(WaBotSetting::current()->appId()),
            ],
        ]);
    }

    /** POST api/whatsapp/business-profile/sync {fields}: المحدد وحده، ثم قراءة للتحقق */
    public function sync(Request $request): JsonResponse
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        abort_unless(FeatureFlags::on('business_profile_sync'), 404);
        $data = $request->validate([
            'fields' => 'required|array|min:1',
            'fields.*' => ['required', 'string', Rule::in(BusinessProfileSync::FIELDS)],
        ], ['fields.*' => 'اختر حقلاً واحداً على الأقل من الحقول المعروفة.']);

        $r = BusinessProfileSync::sync(array_values(array_unique($data['fields'])));

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** POST api/whatsapp/business-profile/photo: ملف مرفوع أو use_company_logo=1. بلا App ID: 409 */
    public function photo(Request $request): JsonResponse
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        abort_unless(FeatureFlags::on('business_profile_photo'), 404);
        $data = $request->validate([
            'photo' => 'nullable|file|max:5120|required_without:use_company_logo',
            'use_company_logo' => 'nullable|boolean',
        ], ['photo.max' => 'أقصى حجم للصورة 5 ميغابايت.', 'photo.required_without' => 'ارفع صورة أو اختر شعار الشركة.']);
        if (blank(WaBotSetting::current()->appId())) {
            return response()->json(['ok' => false, 'code' => 'APP_ID_MISSING', 'error' => 'أدخل App ID في الإعدادات أولاً.'], 409);
        }

        $file = $request->file('photo');
        $r = BusinessProfileSync::photo($file ? (string) file_get_contents($file->getRealPath()) : null, $file ? 'upload' : 'company_logo');

        return response()->json(array_diff_key($r, ['status' => 1]), $r['ok'] ? 200 : ($r['status'] ?? 422));
    }
}
