<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaContactCard;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaPlace;
use App\Services\WhatsApp\Outbound\OutboundPolicy;
use App\Services\WhatsApp\QuickReplyText;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\FeatureFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * الرسائل الغنية من الصندوق (E33): التصحيح المقتبس، وتفاعل الموظف، ودبوس الموقع، وبطاقات الاتصال.
 * كل مسار: whatsapp.reply، و{conversation} بالربط المسيّج (E04)، و{message} من المحادثة نفسها وإلا 404، وعلم ميزته.
 * الإرسال كله عبر WhatsAppChannel (الأقفال والنافذة والسياسة والدفتر) بمفتاح الموظف (Idempotency-Key).
 */
class WhatsAppRichMessageController extends Controller
{
    /**
     * رسالة تصحيح مقتبسة من الرسالة الخاطئة (C009): لا تعديل ولا حذف في Cloud API، والأصل لا يتغيّر أبداً (دليل عند نزاع
     * على سعر). الأصل يحمل _corrected_by، والتصحيح _meta.corrects_message_id، وصف تدقيق بلا نص.
     */
    public function correct(Request $request, WaConversation $conversation, WaMessage $message)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        WhatsAppThreadPinController::ownMessage($conversation, $message);
        if (!FeatureFlags::on('wa_reply_quote')) {
            return $this->refuse('FEATURE_OFF', 'رسالة التصحيح غير مفعّلة.');
        }
        $data = $request->validate(['body' => 'required|string|max:4000', 'ack_warnings' => 'sometimes|boolean']);

        if ($message->direction !== 'out' || !in_array($message->source, ['staff', 'bot', 'system'], true)
            || !in_array($message->msg_type, ['text', 'image', 'document'], true)) {
            return $this->refuse('NOT_CORRECTABLE', 'يُصحَّح ما أرسلناه من نص أو صورة أو مستند وحده.');
        }
        if (blank($message->wamid) || in_array($message->status, ['failed', 'queued', 'unknown', 'blocked'], true)) {
            return $this->refuse('NOT_CORRECTABLE', 'فشل إرسال الرسالة الأصلية فلا يمكن اقتباسها.');
        }
        if (!$conversation->isWindowOpen()) {
            return $this->refuse(WhatsAppChannel::ERR_WINDOW, 'النافذة مغلقة — تواصل مع العميل هاتفياً.');
        }
        if ($unfilled = QuickReplyText::unfilled($data['body'])) {
            return $this->refuse('UNFILLED_VARIABLE', 'في الرسالة متغير لم يُملأ: {{'.$unfilled[0].'}} — أكمله قبل الإرسال');
        }
        // التحذير نفسه لرد الموظف (E15.9): يؤكده ولا يُمنع
        $warnings = OutboundPolicy::evaluate($conversation, ['text' => ['body' => $data['body']]], $data['body'], 'staff')->warnings;
        if ($warnings && !$request->boolean('ack_warnings')) {
            return response()->json(['ok' => false, 'code' => 'POLICY_WARN', 'reasons' => $warnings,
                'error' => 'الرسالة تحوي ما يشبه '.OutboundPolicy::describe($warnings).'. سياسة واتساب تمنع طلب هذه الأرقام أو مشاركتها. أرسل رغم ذلك؟'], 409);
        }

        $res = WhatsAppChannel::sendText($conversation, $data['body'], 'staff', auth()->id(), sendKey: WhatsAppController::staffSendKey($request),
            opts: ['reply_to' => $message, 'meta' => ['corrects_message_id' => $message->id]]);
        if ($res['ok'] && empty($res['replayed'])) {
            $newId = (int) ($res['row_id'] ?? WaMessage::where('conversation_id', $conversation->id)->where('wamid', $res['message_id'])->value('id'));
            DB::transaction(function () use ($message, $newId) {
                $row = WaMessage::whereKey($message->id)->lockForUpdate()->first();
                $payload = (array) ($row->payload ?? []);
                $payload['_corrected_by'][] = ['message_id' => $newId, 'at' => now()->toIso8601String(), 'staff_id' => auth()->id()];
                // الحمولة وحدها: body الأصل لا يُمس
                $row->forceFill(['payload' => $payload])->save();
            });
            AuditLog::safe(AuditAction::WaMsgCorrect, 'wa_message', $message->id, null, ['new_message_id' => $newId, 'conversation_id' => $conversation->id]);
            if ($warnings) {
                AuditLog::safe(AuditAction::WaPolicyOverride, 'wa_conversation', $conversation->id, null, ['reasons' => $warnings]);
            }
        }

        return response()->json($res, WhatsAppController::sendStatus($res));
    }

    /** تفاعل الموظف (C008): إقرار لا رد (لا يوقف البوت ولا يمس شارة التدخّل). '' يزيل التفاعل */
    public function react(Request $request, WaConversation $conversation, WaMessage $message)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        WhatsAppThreadPinController::ownMessage($conversation, $message);
        $data = $request->validate(['emoji' => 'present|nullable|string|max:16']);

        $res = WhatsAppChannel::sendReaction($conversation, $message, (string) ($data['emoji'] ?? ''), (int) auth()->id(),
            WhatsAppController::staffSendKey($request));

        return response()->json($res, WhatsAppController::sendStatus($res));
    }

    /** دبوس موقع من القائمة المعتمدة، مقتبساً اختيارياً */
    public function sendLocation(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        if (!FeatureFlags::on('wa_share_cards')) {
            return $this->refuse('FEATURE_OFF', 'إرسال الموقع غير مفعّل.');
        }
        $data = $request->validate(['place_id' => 'required|integer|min:1', 'reply_to_id' => 'nullable|integer|min:1']);
        $place = WaPlace::active()->find($data['place_id']);
        if (!$place) {
            return $this->refuse('PLACE_INACTIVE', 'هذا الموقع غير فعّال.');
        }
        [$replyTo, $refused] = $this->replyTarget($conversation, $data['reply_to_id'] ?? null);
        if ($refused) {
            return $refused;
        }

        $res = WhatsAppChannel::sendLocation($conversation, $place, 'staff', (int) auth()->id(),
            ['send_key' => WhatsAppController::staffSendKey($request), 'reply_to' => $replyTo]);

        return response()->json($res, WhatsAppController::sendStatus($res));
    }

    /** بطاقة أو حتى 3 من القائمة المعتمدة */
    public function sendContact(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        if (!FeatureFlags::on('wa_share_cards')) {
            return $this->refuse('FEATURE_OFF', 'إرسال البطاقات غير مفعّل.');
        }
        $data = $request->validate([
            'card_ids' => 'required|array|min:1|max:'.WaContactCard::MAX_PER_MESSAGE,
            'card_ids.*' => 'integer|distinct|min:1',
            'reply_to_id' => 'nullable|integer|min:1',
        ], ['card_ids.max' => 'اختر حتى '.WaContactCard::MAX_PER_MESSAGE.' بطاقات.']);
        $cards = WaContactCard::active()->whereIn('id', $data['card_ids'])->orderBy('sort_order')->orderBy('id')->get();
        if ($cards->count() !== count($data['card_ids'])) {
            return $this->refuse('CARD_INACTIVE', 'بطاقة غير فعّالة.');
        }
        [$replyTo, $refused] = $this->replyTarget($conversation, $data['reply_to_id'] ?? null);
        if ($refused) {
            return $refused;
        }

        $res = WhatsAppChannel::sendContacts($conversation, $cards, 'staff', (int) auth()->id(),
            ['send_key' => WhatsAppController::staffSendKey($request), 'reply_to' => $replyTo]);

        return response()->json($res, WhatsAppController::sendStatus($res));
    }

    /** الاقتباس بمعرّفنا داخل المحادثة نفسها، وبالعلم wa_reply_quote */
    private function replyTarget(WaConversation $conversation, ?int $id): array
    {
        if (!$id) {
            return [null, null];
        }
        if (!FeatureFlags::on('wa_reply_quote')) {
            return [null, $this->refuse('FEATURE_OFF', 'الرد المقتبس غير مفعّل.')];
        }
        $target = $conversation->messages()->whereKey($id)->first();

        return WhatsAppChannel::canQuote($conversation, $target)
            ? [$target, null]
            : [null, $this->refuse('BAD_REPLY_TARGET', 'لا يمكن الاقتباس من هذه الرسالة.')];
    }

    private function refuse(string $code, string $error)
    {
        return response()->json(['ok' => false, 'code' => $code, 'error' => $error, 'message_id' => null], 422);
    }
}
