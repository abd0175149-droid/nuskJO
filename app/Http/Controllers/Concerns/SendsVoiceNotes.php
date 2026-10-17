<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\VoiceConversionException;
use App\Exceptions\VoiceNoteException;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\MediaConverter;
use App\Services\WhatsApp\MetaError;
use App\Services\WhatsApp\Outbound\OutboundFileStore;
use App\Services\WhatsApp\Outbound\VoiceNote;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\VoiceNoteValidator;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * فرع voice=1 في send-file (E34.5، العلم staff_voice_notes): يرث من E32 الصلاحية والسياج وحد wa-files وIdempotency-Key
 * وتأكيد المستلم، والحجز بالمفتاح والنسخة الخاصة والرفع المؤجل في الطابور (SendWhatsAppMediaJob ثم sendVoice).
 *
 * الترتيب: الأقفال (النافذة والإيقاف والنطاق) قبل أي عمل ثقيل، فلا يعمل ffmpeg لمحادثة نافذتها مغلقة. ثم الملف الخام
 * بنوعه الحقيقي (webm أو ogg أو mp4) وحده 3MB، ثم التحويل إلى ogg/opus أحادي، ثم شرط ميتا (VoiceNoteValidator)، ثم الحجز.
 * المفتاح نفسه مرة ثانية يعيد نتيجة الأولى قبل التحويل، فالنقر المزدوج لا يشغّل ffmpeg مرتين ولا يرسل مرتين.
 * الردود: 503 المحوّل مشغول، و413 الملف الخام كبير، و422 طويل أو غير صالح أو النافذة مغلقة. لا stderr في أي رد.
 */
trait SendsVoiceNotes
{
    protected function storeVoice(Request $request, WaConversation $conversation): JsonResponse
    {
        abort_unless(FeatureFlags::on('staff_voice_notes'), 404);
        $user = $request->user();

        $key = SendKey::staff((string) $request->header('Idempotency-Key', ''));
        if ($key === null) {
            return response()->json(['ok' => false, 'code' => 'IDEMPOTENCY_KEY_REQUIRED',
                'error' => 'مفتاح الإرسال مفقود — حدّث الصفحة ثم سجّل من جديد.'], 422);
        }
        $data = $request->validate([
            'file' => 'required|file',
            'confirm_conversation_id' => 'required|integer',
        ], [
            'file.required' => 'لا تسجيل مرفق.',
            'file.uploaded' => 'تعذّر رفع التسجيل إلى الخادم.',
            'confirm_conversation_id.required' => 'أكّد المستلم قبل الإرسال.',
        ]);
        if ((int) $data['confirm_conversation_id'] !== $conversation->id) {
            return response()->json(['ok' => false, 'code' => 'RECIPIENT_MISMATCH', 'error' => MetaError::local('RECIPIENT_MISMATCH')->staffMessage], 422);
        }
        if ($replay = $this->voiceReplay($key, $conversation)) {
            return $replay;
        }
        // الأقفال قبل أي عمل ثقيل: لا تحويل ولا حفظ لمحادثة لا يُرسل إليها
        if ($fail = WhatsAppChannel::preflight($conversation, 'staff', $user->id)) {
            return response()->json($fail, 422);
        }

        $file = $request->file('file');
        $size = (int) $file->getSize();
        if ($size > (int) config('whatsapp.voice.max_upload_bytes', 3_000_000)) {
            return response()->json(['ok' => false, 'code' => 'VOICE_UPLOAD_TOO_LARGE', 'error' => 'التسجيل أكبر من 3MB — سجّل رسالة أقصر.'], 413);
        }
        $mime = VoiceNoteValidator::mime((string) $file->getRealPath());
        if ($size < 1 || !VoiceNoteValidator::rawMimeAllowed($mime)) {
            WaLog::info('wa.voice.rejected', ['conv_id' => $conversation->id, 'code' => 'VOICE_INPUT_TYPE', 'mime' => $mime, 'bytes' => $size]);

            return response()->json(['ok' => false, 'code' => 'VOICE_INPUT_TYPE', 'error' => 'صيغة التسجيل غير مدعومة — سجّل من المتصفح من جديد.'], 422);
        }
        if (!MediaConverter::available()) {
            $e = VoiceConversionException::unavailable();

            return response()->json(['ok' => false, 'code' => $e->reason, 'error' => $e->getMessage()], $e->status);
        }

        // نسخة خام باسم يولّده الخادم في المجلد المؤقت 0700؛ المحوّل يحذفها دائماً
        $raw = MediaConverter::tmpPath('in');
        if (!@copy((string) $file->getRealPath(), $raw)) {
            return response()->json(['ok' => false, 'code' => 'VOICE_CONVERT_FAILED', 'error' => VoiceConversionException::failed()->getMessage()], 422);
        }
        @chmod($raw, 0600);

        try {
            $voice = MediaConverter::toVoice($raw);
        } catch (VoiceNoteException $e) {
            WaLog::info('wa.voice.rejected', ['conv_id' => $conversation->id, 'code' => $e->reason, 'mime' => $mime, 'bytes' => $size]);
            $res = response()->json(['ok' => false, 'code' => $e->reason, 'error' => $e->getMessage()], $e->status);

            return $e->status === 503 ? $res->header('Retry-After', '5') : $res;
        }

        try {
            if ($reason = VoiceNoteValidator::check($voice['path'])) {
                WaLog::warning('wa.voice.rejected', ['conv_id' => $conversation->id, 'code' => 'VOICE_INVALID', 'bytes' => $voice['bytes']]);

                return response()->json(['ok' => false, 'code' => 'VOICE_INVALID', 'error' => $reason], 422);
            }
            $stored = OutboundFileStore::put($voice['path'], 'ogg');
        } finally {
            @unlink($voice['path']);
        }

        $seconds = round((float) $voice['seconds'], 1);

        return $this->queueWhatsAppFile($conversation, VoiceNote::message(null, $seconds), $user->id, $key,
            ['mime' => VoiceNote::MIME, 'name' => VoiceNote::FILENAME, 'type' => VoiceNote::TYPE], $stored, ['_voice' => ['seconds' => $seconds]]);
    }

    /**
     * المفتاح نفسه قبل التحويل (نقرتان أو إعادة بعد انقطاع): نتيجة الطلب الأول بأشكال queueWhatsAppFile نفسها، بلا ffmpeg
     * ولا صف ثانٍ. مفتاح لرسالة أخرى (محادثة أو نوع غير هذا) يُرفض. الصف الفاشل حرّر مفتاحه فلا يطابق هنا.
     */
    private function voiceReplay(string $key, WaConversation $conversation): ?JsonResponse
    {
        $row = WaMessage::where('send_key', mb_substr($key, 0, 64))->first(['id', 'conversation_id', 'msg_type', 'status', 'wamid']);
        if (!$row) {
            return null;
        }
        if ((int) $row->conversation_id !== $conversation->id || $row->msg_type !== VoiceNote::TYPE) {
            return response()->json(['ok' => false, 'code' => 'IDEMPOTENCY_MISMATCH', 'error' => 'مفتاح الإرسال مستعمل لرسالة أخرى — سجّل من جديد.'], 422);
        }
        WaLog::info('wa.send.replayed', ['conv_id' => $conversation->id, 'message_id' => $row->id, 'status' => $row->status]);

        return match (true) {
            $row->status === WaMessage::STATUS_QUEUED => response()->json(['ok' => true, 'status' => 'queued', 'row_id' => $row->id, 'message_id' => null, 'replayed' => true], 202),
            in_array($row->status, ['sent', 'delivered', 'read', 'played'], true) => response()->json(['ok' => true, 'status' => 'replayed', 'row_id' => $row->id, 'message_id' => $row->wamid, 'replayed' => true]),
            default => response()->json(['ok' => false, 'code' => 'OUTCOME_UNKNOWN', 'error' => MetaError::local('OUTCOME_UNKNOWN')->staffMessage,
                'status' => (string) $row->status, 'row_id' => $row->id, 'replayed' => false, 'conflict' => true], 409),
        };
    }
}
