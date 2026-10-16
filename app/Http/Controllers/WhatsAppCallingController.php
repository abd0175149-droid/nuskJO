<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Services\WhatsApp\CallEventHandler;
use App\Services\WhatsApp\CallingSettings;
use App\Services\WhatsApp\RuleTextGuard;
use App\Services\WhatsApp\Settings\SettingsWriter;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * بطاقة «📞 المكالمات» (E39، العلم calls_webhook، whatsapp.settings): قراءة إعدادات الاتصال من ميتا، وحفظ طريقة الرد
 * والرفض الآلي ونص المتابعة (بحارس الردود الحتمية، ويُدقَّق). لا مسار يكتب إعدادات الاتصال عند ميتا: تفعيل الاتصال
 * على رقم المبيعات قرار نادر متعمد من الأمر whatsapp:calling-settings بتأكيد.
 */
class WhatsAppCallingController extends Controller
{
    public function show(): JsonResponse
    {
        $this->authorizeCalls();

        return response()->json(['calling' => CallingSettings::panel()])->header('Cache-Control', 'no-store');
    }

    /** «قراءة من ميتا»: GET {pnid}/settings وتُحفظ آخر قراءة للعرض (بلا رفع نسخة الإعدادات) */
    public function refresh(): JsonResponse
    {
        $this->authorizeCalls();
        $res = WhatsAppChannel::getCallingSettings();
        if (!$res['ok']) {
            return response()->json(['message' => $res['error'] ?? 'تعذّرت القراءة من ميتا', 'code' => $res['code'] ?? null], 502);
        }
        CallingSettings::remember((array) ($res['data'] ?? []), 'read');

        return response()->json(['calling' => CallingSettings::panel()]);
    }

    /** طريقة الرد والرفض الآلي ونصا المتابعة (الفارغ = النص المقترح، والإنجليزي اختياري) */
    public function update(Request $request): JsonResponse
    {
        $this->authorizeCalls();
        $data = $request->validate([
            'answer_mode' => ['required', Rule::in(CallEventHandler::ANSWER_MODES)],
            'auto_reject' => 'required|boolean',
            'followup_text' => 'nullable|string|max:600',
            'followup_text_en' => 'nullable|string|max:600',
        ], ['answer_mode.in' => 'طريقة الرد: none أو webrtc أو sip']);

        $errors = [];
        foreach (['followup_text', 'followup_text_en'] as $key) {
            $text = trim((string) ($data[$key] ?? ''));
            if ($text !== '' && ($found = RuleTextGuard::errors($text)) !== []) {
                $errors[$key] = implode(' ', $found);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        SettingsWriter::apply([
            'calls_answer_mode' => $data['answer_mode'],
            'calls_auto_reject' => (bool) $data['auto_reject'],
            'calls_followup_text' => filled($data['followup_text'] ?? null) ? trim($data['followup_text']) : null,
            'calls_followup_text_en' => filled($data['followup_text_en'] ?? null) ? trim($data['followup_text_en']) : null,
        ], null, AuditAction::WaCallsFollowupUpdate);

        return response()->json(['calling' => CallingSettings::panel()]);
    }

    private function authorizeCalls(): void
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        abort_unless(CallEventHandler::enabled(), 404);
    }
}
