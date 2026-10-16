<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\MessageTranslator;
use App\Services\WhatsApp\NumberGuard;
use App\Services\WhatsApp\Outbound\OutboundPolicy;
use App\Services\WhatsApp\QuickReplyText;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\TranslationRefused;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * الترجمة عند الطلب (E29) خلف العلم staff_translation: ترجمة رسالة واردة للموظف (مخزّنة)، ومعاينة ترجمة رده،
 * وإرسال الرد المترجم بعد التحقق في الخادم من رمز المعاينة وتطابق الأرقام. الإرسال عبر WhatsAppChannel بأقفاله كلها
 * ومفتاح Idempotency-Key (E19). مسار إرسال مستقل لا حقل في send، فيبقى مسار الرد العادي كما هو.
 */
class WhatsAppTranslationController extends Controller
{
    /** «🌐 ترجم» على رسالة واردة: مرة واحدة، والمرات التالية من المخزّن (cached) بلا نداء */
    public function translate(WaConversation $conversation, WaMessage $message)
    {
        abort_unless(auth()->user()->can('whatsapp.view'), 403);
        abort_unless(MessageTranslator::enabled(), 404);
        WhatsAppThreadPinController::ownMessage($conversation, $message);

        try {
            return response()->json(MessageTranslator::translateInbound($message, auth()->user()));
        } catch (TranslationRefused $e) {
            return $e->toResponse();
        }
    }

    /** معاينة ترجمة رد الموظف: لا يُخزَّن شيء ولا يُرسل شيء */
    public function translateReply(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        abort_unless(MessageTranslator::enabled(), 404);
        $data = $request->validate([
            'text' => 'required|string|max:'.MessageTranslator::MAX_REPLY_CHARS,
            'target' => ['required', 'string', Rule::in(array_keys(MessageTranslator::REPLY_TARGETS))],
        ]);

        try {
            return response()->json(MessageTranslator::translateReply($conversation, $data['text'], $data['target'], auth()->user()));
        } catch (TranslationRefused $e) {
            return $e->toResponse();
        }
    }

    /**
     * إرسال الرد المترجم: رمز المعاينة لنفس المستخدم والمحادثة والنص المصدر والهدف (وإلا TRANSLATION_UNVERIFIED)،
     * ثم أرقام الأصل مقابل النص النهائي بعد تعديل الموظف (وإلا NUMBERS_MISMATCH)، كلاهما قبل أي نداء لميتا.
     * ثم مسار الرد العادي: المتغيرات غير المملوءة، وتحذير البيانات الحساسة (409)، وWhatsAppChannel::sendText.
     */
    public function sendTranslated(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        abort_unless(MessageTranslator::enabled(), 404);
        $data = $request->validate([
            'body' => 'required|string|max:4000',
            'ack_warnings' => 'sometimes|boolean',
            'translation' => 'required|array',
            'translation.source_text' => 'required|string|max:'.MessageTranslator::MAX_REPLY_CHARS,
            'translation.target' => ['required', 'string', Rule::in(array_keys(MessageTranslator::REPLY_TARGETS))],
            'translation.check_token' => 'required|string|max:128',
        ]);
        $tr = $data['translation'];
        $me = auth()->user();

        // المنشئ يرسل مفتاحاً لكل نص؛ لا مفتاح مولَّد هنا، فنقرتان لا تُخرجان رسالتين
        $key = SendKey::staff((string) $request->header('Idempotency-Key', ''));
        if ($key === null) {
            return response()->json(['ok' => false, 'code' => 'IDEMPOTENCY_KEY_REQUIRED', 'error' => 'أعد تحميل الصفحة ثم أرسل مرة أخرى'], 422);
        }
        if (!MessageTranslator::verifyToken($tr['check_token'], (int) $conversation->id, (int) $me->id, $tr['source_text'], $tr['target'])) {
            return response()->json(['ok' => false, 'code' => 'TRANSLATION_UNVERIFIED',
                'error' => 'انتهت صلاحية المعاينة أو تغيّر النص الأصلي — اضغط «معاينة الترجمة» من جديد'], 422);
        }
        $numbers = NumberGuard::compare($tr['source_text'], $data['body'], $tr['target']);
        if (!$numbers['ok']) {
            return response()->json(['ok' => false, 'code' => 'NUMBERS_MISMATCH',
                'error' => $numbers['spelled'] ? 'في الترجمة أرقام مكتوبة بالحروف — اكتبها أرقاماً' : 'أرقام الترجمة لا تطابق أرقام النص الأصلي',
                'missing' => $numbers['missing'], 'extra' => $numbers['extra'], 'spelled_words' => $numbers['spelled_words']], 422);
        }

        if ($unfilled = QuickReplyText::unfilled($data['body'])) {
            return response()->json(['ok' => false, 'code' => 'UNFILLED_VARIABLE',
                'error' => 'في الرسالة متغير لم يُملأ: {{'.$unfilled[0].'}} — أكمله قبل الإرسال'], 422);
        }
        $warnings = OutboundPolicy::evaluate($conversation, ['text' => ['body' => $data['body']]], $data['body'], 'staff')->warnings;
        if ($warnings && !$request->boolean('ack_warnings')) {
            return response()->json([
                'ok' => false, 'code' => 'POLICY_WARN', 'reasons' => $warnings,
                'error' => 'الرسالة تحوي ما يشبه '.OutboundPolicy::describe($warnings).'. سياسة واتساب تمنع طلب هذه الأرقام أو مشاركتها. أرسل رغم ذلك؟',
            ], 409);
        }

        $res = WhatsAppChannel::sendText($conversation, $data['body'], 'staff', $me->id, sendKey: $key);
        if (empty($res['replayed'])) {
            if ($warnings && $res['ok']) {
                AuditLog::safe(AuditAction::WaPolicyOverride, 'wa_conversation', $conversation->id, null, ['reasons' => $warnings]);
            }
            // قد تكون وصلت (غير مؤكد): الوسم يبقى صحيحاً لما رآه العميل
            if ($res['ok'] || !empty($res['unknown'])) {
                MessageTranslator::tagSent($conversation, $res, $data['body'], $tr['source_text'], $tr['target'], (int) $me->id);
            }
        }

        return response()->json($res, WhatsAppController::sendStatus($res));
    }
}
