<?php

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Outbound\OutboundFileStore;
use App\Services\WhatsApp\Outbound\OutboundMessage;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\AuditActor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * إرسال ملف محجوز (E32.7): الرفع إلى ميتا مؤجل دائماً هنا، لا في طلب الويب. طابور wa-media-out يخدمه عامل laravel-media
 * قبل أرشفة الوارد، فلا يؤخر ردود البوت (wa-replies) ولا تؤخره دفعة صور واردة.
 *
 * الترتيب: الصف ما زال queued ولم تبدأ محاولته، ثم عمره (لا يصل العميلَ ملف بعد ساعات)، ثم preflight (النافذة قد
 * أُغلقت منذ الطلب)، ثم الرفع، ثم send() على الصف نفسه بالمفتاح نفسه، وفيه الأقفال كلها من جديد. لا إعادة آلية (tries=1):
 * رفع ملف كبير مرة ثانية بعد مهلة قد يكرر الإرسال. أي فشل قبل الإرسال يحسم الصف failed بنص عربي، والمفتاح محرَّر.
 * الحمولة رقم الصف وحده (لا اسم ملف ولا رقم هاتف).
 */
final class SendWhatsAppMediaJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** أقل من --timeout للعامل (125): الرفع حتى 85 ثانية والإرسال حتى 25 */
    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 900;

    public function __construct(public int $messageId)
    {
        $this->onQueue(config('whatsapp.outbound.queue', 'wa-media-out'));
    }

    public function uniqueId(): string
    {
        return (string) $this->messageId;
    }

    public function handle(): void
    {
        $row = WaMessage::find($this->messageId);
        // متساوية الأثر: المحسوم أو ما بدأ إرساله لا يُلمس
        if (!$row || $row->direction !== 'out' || $row->status !== WaMessage::STATUS_QUEUED || (int) $row->attempts !== 0) {
            return;
        }
        $conv = WaConversation::find($row->conversation_id);
        if (!$conv) {
            WhatsAppChannel::failReserved($row, ['code' => 'MEDIA_SEND_FAILED']);

            return;
        }
        if ($row->created_at?->lt(now()->subMinutes((int) config('whatsapp.outbound.queue_max_minutes', 10)))) {
            WhatsAppChannel::failReserved($row, ['code' => 'MEDIA_QUEUE_STALE']);

            return;
        }
        if ($fail = WhatsAppChannel::preflight($conv, (string) $row->source, $row->staff_id)) {
            WhatsAppChannel::failReserved($row, $fail);

            return;
        }
        if (!$row->media_mime || !OutboundFileStore::exists($row->media_path)) {
            WhatsAppChannel::failReserved($row, ['code' => 'MEDIA_FILE_MISSING']);

            return;
        }

        $local = OutboundFileStore::local((string) $row->media_path);
        try {
            $up = WhatsAppChannel::uploadMedia($conv, $local[0], (string) $row->media_mime, (string) ($row->media_filename ?: 'file'), (string) $row->source, $row->staff_id);
        } finally {
            OutboundFileStore::release($local);
        }
        if (!$up['ok']) {
            WhatsAppChannel::failReserved($row, $up);

            return;
        }

        $message = OutboundMessage::media((string) $row->msg_type, $up['media_id'], $row->media_filename, data_get($row->payload, '_caption'))
            ->withReplyTo($row->reply_to_wamid);
        $res = WhatsAppChannel::send($conv->fresh() ?? $conv, $message, (string) $row->source, $row->staff_id, [
            'row' => $row,
            'send_key' => $row->send_key,
            'media' => ['mime' => $row->media_mime, 'sha256' => $row->media_sha256, 'size' => $row->media_size,
                'filename' => $row->media_filename, 'path' => $row->media_path],
        ]);

        if ($res['ok']) {
            self::audit($row->fresh() ?? $row);
        } elseif (empty($res['unknown'])) {
            // رفض صريح بعد الرفع: لا يبقى الملف عند ميتا 30 يوماً بلا رسالة (محاولة واحدة، والفشل يُعدّ فقط)
            WhatsAppChannel::deleteMedia($up['media_id']);
        }
    }

    /** وظيفة انتهت مهلتها أو رمت: الصف failed إن لم يبدأ إرساله (وإلا يحسمه الدفتر أو المنظّف «غير مؤكد») */
    public function failed(?\Throwable $e): void
    {
        WaLog::warning('wa.media.job_failed', ['message_id' => $this->messageId, 'exception' => $e]);
        if ($row = WaMessage::find($this->messageId)) {
            WhatsAppChannel::failReserved($row, ['code' => 'MEDIA_SEND_FAILED']);
        }
    }

    /**
     * سجل كل ملف خرج لعميل (E32.6): النوع والحجم وأول 16 حرفاً من البصمة ونوع الوثيقة، بلا اسم الملف (قد يحمل اسماً
     * أو رقماً، وهو في صف الرسالة لمن يملك رؤيتها). التأشيرة بدرجة تحذير. الفاعل الموظف الذي ضغط «إرسال».
     */
    public static function audit(WaMessage $row): void
    {
        $payload = (array) ($row->payload ?? []);
        $kind = $payload['_doc_kind'] ?? null;
        $invoiceId = isset($payload['_invoice_id']) ? (int) $payload['_invoice_id'] : null;
        $changes = array_filter([
            'message_id' => $row->id, 'type' => $row->msg_type, 'mime' => $row->media_mime, 'size' => $row->media_size,
            'sha256' => $row->media_sha256 ? substr((string) $row->media_sha256, 0, 16) : null,
            'via' => $invoiceId ? 'invoice' : 'inbox', 'doc_kind' => $kind,
        ], fn ($v) => $v !== null);

        AuditActor::as('user', 'job:send-media', function () use ($row, $changes, $kind, $invoiceId) {
            AuditLog::safe(AuditAction::WaFileSent, 'wa_conversation', $row->conversation_id, null, $changes,
                $kind === 'visa' ? AuditSeverity::Warn : null);
            if ($invoiceId) {
                AuditLog::safe(AuditAction::WaInvoiceSent, 'invoice', $invoiceId, isset($row->payload['_invoice_number']) ? (string) $row->payload['_invoice_number'] : null,
                    ['conversation_id' => $row->conversation_id, 'message_id' => $row->id, 'sha256' => $changes['sha256'] ?? null]);
            }
        }, $row->staff_id);
    }
}
