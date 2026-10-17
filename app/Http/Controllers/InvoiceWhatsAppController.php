<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\QueuesWhatsAppFiles;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WaConversation;
use App\Services\Invoices\InvoicePdfService;
use App\Services\WhatsApp\MetaError;
use App\Services\WhatsApp\Outbound\OutboundFileStore;
use App\Services\WhatsApp\Outbound\OutboundMessage;
use App\Services\WhatsApp\Outbound\OutboundPolicy;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\FeatureFlags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * إرسال الفاتورة PDF على واتساب (E32.9، العلم invoice_whatsapp_send): لمن يملك invoices.view وwhatsapp.reply، إلى محادثة
 * مرتبطة بعميل الفاتورة نفسه ومرئية للموظف، بتأكيد المستلم في الخادم وIdempotency-Key. الفاتورة المعتمدة وحدها افتراضياً
 * (قرار المالك). الملف يُصيَّر من القالب المستقل بلا تكلفة، ويُرفع ويُرسل مستنداً بالمعرّف في الطابور، ويظهر في سجل الفاتورة.
 */
class InvoiceWhatsAppController extends Controller
{
    use QueuesWhatsAppFiles;

    private const NO_TARGETS = 'لا توجد محادثة واتساب مفتوحة النافذة لهذا العميل — اطلب منه مراسلتنا أولاً (لا نستخدم القوالب).';

    /** محادثات عميل الفاتورة المرئية للموظف: الاسم وآخر 4 أرقام والنافذة. بلا رقم كامل */
    public function targets(Request $request, Invoice $invoice)
    {
        $this->authorizeSend($request->user());
        $rows = self::conversations($invoice, $request->user())->orderByDesc('last_inbound_at')->limit(10)->get();
        $targets = $rows->map(fn (WaConversation $c) => [
            'conversation_id' => $c->id,
            'name' => $c->label(),
            'last4' => $c->hasPhone() ? substr(PhoneNormalizer::digits($c->phone), -4) : null,
            'client_code' => $c->client?->code,
            'window_open' => $c->isWindowOpen(),
            'window_left' => $c->windowMinutesLeft(),
        ])->values();

        return response()->json([
            'targets' => $targets,
            'sendable' => self::sendable($invoice),
            'filename' => InvoicePdfService::filename($invoice),
            'caption' => InvoicePdfService::defaultCaption($invoice),
            'message' => $targets->contains('window_open', true) ? null : self::NO_TARGETS,
        ]);
    }

    public function send(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        $this->authorizeSend($user);

        $key = SendKey::staff((string) $request->header('Idempotency-Key', ''));
        if ($key === null) {
            return response()->json(['ok' => false, 'code' => 'IDEMPOTENCY_KEY_REQUIRED', 'error' => 'مفتاح الإرسال مفقود — حدّث الصفحة ثم أعد المحاولة.'], 422);
        }
        $data = $request->validate([
            'conversation_id' => 'required|integer',
            'confirm_conversation_id' => 'required|integer',
            'caption' => 'nullable|string|max:'.OutboundMessage::MAX_CAPTION,
            'ack_warnings' => 'sometimes|boolean',
        ], ['confirm_conversation_id.required' => 'أكّد المستلم قبل الإرسال.', 'caption.max' => 'التعليق حتى '.OutboundMessage::MAX_CAPTION.' حرفاً.']);

        if (!self::sendable($invoice)) {
            return response()->json(['ok' => false, 'code' => 'INVOICE_NOT_SENDABLE', 'error' => 'لا تُرسل فاتورة غير معتمدة.'], 422);
        }
        if ((int) $data['confirm_conversation_id'] !== (int) $data['conversation_id']) {
            return response()->json(['ok' => false, 'code' => 'RECIPIENT_MISMATCH', 'error' => MetaError::local('RECIPIENT_MISMATCH')->staffMessage], 422);
        }
        $conversation = self::conversations($invoice, $user)->whereKey((int) $data['conversation_id'])->first();
        if (!$conversation) {
            return response()->json(['ok' => false, 'code' => 'NOT_CLIENT_CONVERSATION', 'error' => 'المحادثة ليست لعميل هذه الفاتورة أو خارج نطاقك.'], 422);
        }
        if ($fail = WhatsAppChannel::preflight($conversation, 'staff', $user->id)) {
            return response()->json($fail, 422);
        }

        $caption = isset($data['caption']) && trim($data['caption']) !== '' ? $data['caption'] : InvoicePdfService::defaultCaption($invoice);
        $message = OutboundMessage::document(null, InvoicePdfService::filename($invoice), $caption);
        $warnings = OutboundPolicy::evaluate($conversation, $message->payload(), $message->displayBody(), 'staff')->warnings;
        if ($warnings && !$request->boolean('ack_warnings')) {
            return response()->json(['ok' => false, 'code' => 'POLICY_WARN', 'reasons' => $warnings,
                'error' => 'التعليق يحوي ما يشبه '.OutboundPolicy::describe($warnings).'. أرسل رغم ذلك؟'], 409);
        }

        try {
            $pdf = InvoicePdfService::render($invoice);
            [$local, $temporary] = OutboundFileStore::local($pdf['path']);
            try {
                $stored = OutboundFileStore::put($local, 'pdf');
            } finally {
                OutboundFileStore::release([$local, $temporary]);
            }
        } catch (\Throwable $e) {
            WaLog::error('wa.invoice.render_failed', ['conv_id' => $conversation->id, 'exception' => $e]);

            return response()->json(['ok' => false, 'code' => 'INVOICE_PDF_FAILED', 'error' => 'تعذّر توليد PDF الفاتورة — أبلغ المدير.'], 422);
        }

        $info = ['type' => 'document', 'mime' => 'application/pdf', 'name' => (string) $message->filename];
        $response = $this->queueWhatsAppFile($conversation, $message, $user->id, $key, $info, $stored,
            ['_doc_kind' => 'invoice', '_invoice_id' => $invoice->id, '_invoice_number' => (string) $invoice->invoice_number], $warnings);

        return $response->getStatusCode() === 202 ? $response->setData($response->getData(true) + ['conversation_id' => $conversation->id]) : $response;
    }

    private function authorizeSend(User $user): void
    {
        abort_unless($user->can('invoices.view') && $user->can('whatsapp.reply'), 403);
        abort_unless(FeatureFlags::on('invoice_whatsapp_send'), 404);
    }

    /** المعتمدة وحدها افتراضياً (whatsapp.outbound.invoice_send_statuses بقرار المالك) */
    private static function sendable(Invoice $invoice): bool
    {
        return in_array($invoice->status, (array) config('whatsapp.outbound.invoice_send_statuses', ['approved']), true);
    }

    /**
     * محادثات عميل الفاتورة: مرتبطة به، أو برقمه الكامل الموحَّد (E11)، ضمن نطاق الموظف، وليست مدموجة ولا ممحوّة.
     * فاتورة بلا عميل ولا رقم لا تطابق شيئاً.
     */
    private static function conversations(Invoice $invoice, User $user): Builder
    {
        $phone = PhoneNormalizer::canonical($invoice->client_phone);
        $byPhone = strlen($phone) >= 8;

        return WaConversation::query()->with('client:id,name,code')->visibleTo($user)
            ->whereNotIn('wa_conversations.status', ['merged', 'erased'])
            ->when(!$invoice->client_id && !$byPhone, fn ($q) => $q->whereRaw('1 = 0'))
            ->where(fn ($q) => $q
                ->when($invoice->client_id, fn ($w) => $w->where('wa_conversations.client_id', $invoice->client_id))
                ->when($byPhone, fn ($w) => $w->orWhere('wa_conversations.phone', $phone)));
    }
}
