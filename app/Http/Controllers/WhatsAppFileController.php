<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\QueuesWhatsAppFiles;
use App\Http\Controllers\Concerns\SendsVoiceNotes;
use App\Models\WaConversation;
use App\Services\WhatsApp\MetaError;
use App\Services\WhatsApp\Outbound\OutboundFileStore;
use App\Services\WhatsApp\Outbound\OutboundMediaPolicy;
use App\Services\WhatsApp\Outbound\OutboundMessage;
use App\Services\WhatsApp\Outbound\OutboundPolicy;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\FeatureFlags;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * إرسال ملف من صندوق المحادثات (E32.7، العلم staff_file_send): المحادثة بالربط المقيّد (404 خارج النطاق)، وحد معدّل
 * wa-files، وIdempotency-Key إلزامي (E19)، وتأكيد المستلم في الخادم. الملف يُفحص بنوعه الحقيقي ثم يُحفظ نسخة خاصة
 * ويُحجز صفه بالمفتاح، والرفع إلى ميتا مؤجل دائماً في الطابور (مراجعة E20/E32): الرد 202 «في الطابور» برقم الصف،
 * والخيط يتابع الحالة. المفتاح نفسه مرة ثانية يعيد نتيجة الطلب الأول ولا يرفع ثانية.
 */
class WhatsAppFileController extends Controller
{
    use QueuesWhatsAppFiles;
    use SendsVoiceNotes;

    public function store(Request $request, WaConversation $conversation)
    {
        $user = $request->user();
        abort_unless($user->can('whatsapp.reply'), 403);
        // رسالة الموظف الصوتية (E34): فرع بعلمه staff_voice_notes، على الحجز والطابور نفسيهما
        if ($request->boolean('voice')) {
            return $this->storeVoice($request, $conversation);
        }
        abort_unless(FeatureFlags::on('staff_file_send'), 404);

        $key = SendKey::staff((string) $request->header('Idempotency-Key', ''));
        if ($key === null) {
            return response()->json(['ok' => false, 'code' => 'IDEMPOTENCY_KEY_REQUIRED',
                'error' => 'مفتاح الإرسال مفقود — حدّث الصفحة ثم أرسل الملف من جديد.'], 422);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.OutboundMediaPolicy::maxUploadKb()],
            'caption' => 'nullable|string|max:'.OutboundMessage::MAX_CAPTION,
            'reply_to_message_id' => 'nullable|integer|min:1',
            'confirm_conversation_id' => 'required|integer',
            'doc_kind' => ['nullable', 'string', Rule::in(array_keys(OutboundMediaPolicy::DOC_KINDS))],
            'confirm_identity' => 'sometimes|boolean',
            'ack_warnings' => 'sometimes|boolean',
        ], [
            'file.required' => 'اختر ملفاً.',
            'file.uploaded' => 'تعذّر رفع الملف إلى الخادم (قد يكون أكبر من الحد).',
            'file.max' => 'الملف أكبر من الحد المسموح.',
            'caption.max' => 'التعليق حتى '.OutboundMessage::MAX_CAPTION.' حرفاً.',
            'confirm_conversation_id.required' => 'أكّد المستلم قبل الإرسال.',
            'doc_kind.in' => 'نوع الوثيقة غير معروف.',
        ]);

        // تأكيد المستلم في الخادم: نافذة قديمة أو تبويب آخر لا يرسل ملفاً لمحادثة غير التي أكّدها الموظف
        if ((int) $data['confirm_conversation_id'] !== $conversation->id) {
            return response()->json(['ok' => false, 'code' => 'RECIPIENT_MISMATCH', 'error' => MetaError::local('RECIPIENT_MISMATCH')->staffMessage], 422);
        }
        // الأقفال قبل أي حفظ: نافذة مغلقة أو إرسال موقوف لا يترك ملفاً على القرص
        if ($fail = WhatsAppChannel::preflight($conversation, 'staff', $user->id)) {
            return response()->json($fail, 422);
        }

        $file = $request->file('file');
        $info = OutboundMediaPolicy::inspect((string) $file->getRealPath(), (string) $file->getClientOriginalName());
        if (!$info['ok']) {
            WaLog::info('wa.media.rejected', ['conv_id' => $conversation->id, 'code' => $info['code'], 'bytes' => $info['size']]);

            return response()->json(['ok' => false, 'code' => $info['code'], 'error' => $info['error']], 422);
        }

        // نوع الوثيقة (مراجعة E32): إلزامي للمستند، والتأشيرة تحمل رقم الجواز فتطلب تأكيداً ثانياً وتُدقَّق بدرجة تحذير
        $kind = $data['doc_kind'] ?? null;
        if ($info['type'] === 'document' && $kind === null) {
            return response()->json(['ok' => false, 'code' => 'DOC_KIND_REQUIRED', 'error' => 'اختر نوع الوثيقة: فاتورة أو تأشيرة أو تذكرة أو أخرى.'], 422);
        }
        if ($kind === 'visa' && !$request->boolean('confirm_identity')) {
            return response()->json(['ok' => false, 'code' => 'IDENTITY_CONFIRM_REQUIRED',
                'error' => 'التأشيرة تحمل رقم الجواز: أكّد أنها تخص هذا العميل نفسه قبل الإرسال.'], 422);
        }

        // الاقتباس من رسائل المحادثة نفسها وحدها (send() يتحقق ثانية)
        $replyTo = isset($data['reply_to_message_id']) ? $conversation->messages()->whereKey($data['reply_to_message_id'])->value('wamid') : null;
        $caption = isset($data['caption']) && trim($data['caption']) !== '' ? $data['caption'] : null;
        $message = OutboundMessage::media($info['type'], null, $info['name'], $caption)->withReplyTo($replyTo);

        // بيانات حساسة في التعليق أو اسم الملف (E15.9): تحذير يؤكده الموظف، لا منع
        $warnings = OutboundPolicy::evaluate($conversation, $message->payload(), $message->displayBody(), 'staff')->warnings;
        if ($warnings && !$request->boolean('ack_warnings')) {
            return response()->json([
                'ok' => false, 'code' => 'POLICY_WARN', 'reasons' => $warnings,
                'error' => 'التعليق أو اسم الملف يحوي ما يشبه '.OutboundPolicy::describe($warnings)
                    .'. سياسة واتساب تمنع مشاركة هذه الأرقام نصاً. أرسل رغم ذلك؟',
            ], 409);
        }

        $stored = OutboundFileStore::put((string) $file->getRealPath(), (string) $info['ext']);
        $response = $this->queueWhatsAppFile($conversation, $message, $user->id, $key, $info, $stored, array_filter(['_doc_kind' => $kind]), $warnings);

        // تنبيه غير مانع من الفحص (فيديو بلا faststart) يُعرض للموظف مع «في الطابور»
        return $info['warning'] !== null && $response->getStatusCode() === 202
            ? $response->setData($response->getData(true) + ['warning' => $info['warning']])
            : $response;
    }
}
