<?php

namespace App\Services\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaConversation;
use App\Models\WaErasureRequest;
use App\Support\Retention;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * طلبات حذف البيانات (E13.4): تُفتح حتمياً حين يكتب العميل «حذف بياناتي»، مستقلة عن البوت (حتى لو كانت
 * المحادثة بيد موظف). الهوية بصمة HMAC للرقم (أو للمعرّف بلا رقم)، فلا يُخزَّن رقم ولا اسم ولا نص.
 * الإشعار بلا اسم ولا رقم. التنفيذ من سطر الأوامر وحده (whatsapp:forget).
 */
final class ErasureRequests
{
    public static function available(): bool
    {
        return Schema::hasTable('wa_erasure_requests');
    }

    /** من الويبهوك: طلب واحد مفتوح لكل شخص، و«تدخّل» وتوكيل وإشعار للمدراء */
    public static function receive(WaConversation $conv, string $channel = 'whatsapp'): ?WaErasureRequest
    {
        if (!self::available()) {
            return null;
        }
        $subject = self::subjectFor($conv);
        if ($subject === null) {
            return null;
        }

        [$request, $created] = DB::transaction(function () use ($conv, $channel, $subject) {
            if ($open = self::openFor($subject)) {
                return [$open, false];
            }
            $request = self::create($subject, $channel, $conv->id, $conv->wa_user_id ? Retention::userIdHmac($conv->wa_user_id) : null);
            AuditLog::log(AuditAction::WaErasureRequest, 'wa_erasure_request', $request->id, null, ['channel' => $channel, 'conversation_id' => $conv->id]);

            return [$request, true];
        });

        $conv->update(['needs_attention' => true, 'assigned_to' => TopicRouter::assigneeFor($conv)]
            + (ReplyState::available() ? ['attention_reason' => 'data_erasure'] : []));
        if ($created) {
            WhatsAppInbox::notifyAdmins('🗑️ طلب حذف بيانات', 'محادثة #'.$conv->id.' — يُنفَّذ خلال '.config('retention.erasure_due_days').' يوماً', '/whatsapp/erasure-requests');
            WaLog::warning('erasure.received', ['conv_id' => $conv->id, 'source' => $channel]);
        }

        return $request;
    }

    /** طلب من البريد أو بحضور العميل (whatsapp:erasure-request): بالرقم الموحَّد، والمحادثة إن وُجدت */
    public static function openForPhone(string $canonical, string $channel): WaErasureRequest
    {
        $subject = Retention::subjectHmac($canonical);
        $conv = WaConversation::where('phone', $canonical)->first();

        return DB::transaction(function () use ($subject, $channel, $conv) {
            if ($open = self::openFor($subject)) {
                return $open;
            }
            $request = self::create($subject, $channel, $conv?->id, $conv?->wa_user_id ? Retention::userIdHmac($conv->wa_user_id) : null);
            AuditLog::log(AuditAction::WaErasureRequest, 'wa_erasure_request', $request->id, null, ['channel' => $channel, 'conversation_id' => $conv?->id]);

            return $request;
        });
    }

    /** بصمة الشخص: الرقم الموحَّد، وإلا معرّف ميتا (عميل بلا رقم) */
    public static function subjectFor(WaConversation $conv): ?string
    {
        if ($conv->hasPhone() && ($canonical = PhoneNormalizer::canonical($conv->phone)) !== '') {
            return Retention::subjectHmac($canonical);
        }

        return $conv->wa_user_id ? Retention::userIdHmac($conv->wa_user_id) : null;
    }

    private static function openFor(string $subject): ?WaErasureRequest
    {
        return WaErasureRequest::where('subject_hmac', $subject)->whereIn('status', WaErasureRequest::OPEN)->first();
    }

    private static function create(string $subject, string $channel, ?int $convId, ?string $userHmac): WaErasureRequest
    {
        return WaErasureRequest::create([
            'status' => 'received', 'channel' => $channel, 'conversation_id' => $convId, 'subject_hmac' => $subject,
            'wa_user_hmac' => $userHmac, 'received_at' => now(), 'due_at' => now()->addDays((int) config('retention.erasure_due_days', 30)),
        ]);
    }
}
