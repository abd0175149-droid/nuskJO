<?php

namespace App\Services\WhatsApp\Bot;

use App\Enums\HandoffReason;
use App\Models\Invoice;
use App\Models\QuoteRequest;
use App\Models\WaConversation;
use App\Models\WaCustomerNote;
use App\Models\WaNotificationPref;
use App\Services\Invoices\InvoicePdfService;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\CustomerNotes;
use App\Services\WhatsApp\Outbound\OutboundMessage;
use App\Services\WhatsApp\ReplyState;
use App\Services\WhatsApp\System\Catalog;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Support\Facades\Cache;

/**
 * أدوات البوت الخمس الجديدة (2026-11-06). كل ما يخص عميلاً مقيّد بـ financialClientId للمحادثة (عميل مربوط بالهاتف
 * الموقّع وحده)، فلا يصل أحد لفاتورة غيره ولا لطلب غيره ولو ذكر رقمه. لا تكلفة ولا ربح ولا وكيل في أي نتيجة.
 */
final class ExtraTools
{
    private const NOT_LINKED = ['error' => 'المحادثة غير مربوطة بعميل', 'note' => 'لا تذكر بيانات حساب. اعرض تحويله لموظف ليتحقق من هويته.'];

    public static function quoteStatus(array $in, WaConversation $conv): array
    {
        $clientId = $conv->financialClientId();
        $rows = QuoteRequest::query()
            ->where(fn ($q) => $q->where('conversation_id', $conv->id)->when($clientId, fn ($w) => $w->orWhere('client_id', $clientId)))
            ->where('created_at', '>=', now()->subDays(90))->orderByDesc('id')->limit(5)
            ->get(['id', 'request_number', 'type', 'status', 'depart_date', 'quoted_price_jod', 'quoted_at']);

        return [
            'count' => $rows->count(),
            'requests' => $rows->map(fn ($r) => array_filter([
                'request_number' => $r->request_number,
                'type' => $r->type,
                'status' => ['new' => 'قيد التسعير', 'priced' => 'سُعّر وأُرسل السعر', 'booked' => 'حُجز', 'closed' => 'مغلق', 'cancelled' => 'ملغى'][$r->status] ?? $r->status,
                'depart_date' => $r->depart_date?->toDateString(),
                'quoted_price_jod' => $r->status === 'priced' && $r->quoted_price_jod !== null ? (float) $r->quoted_price_jod : null,
            ], fn ($v) => $v !== null))->values()->all(),
            'note' => $rows->isEmpty() ? 'لا طلبات تسعير لهذه المحادثة. اعرض تسجيل طلب جديد بـ request_quote.'
                : 'أخبر العميل بحالة طلبه بإيجاز. لا تعِد بموعد رد، وإن طال الانتظار اعرض تحويله لموظف.',
        ];
    }

    /** نسخة PDF من فاتورة معتمدة للعميل نفسه، 3 مرات في اليوم للمحادثة على الأكثر */
    public static function resendInvoice(array $in, WaConversation $conv): array
    {
        $clientId = $conv->financialClientId();
        if ($clientId === null) {
            return self::NOT_LINKED;
        }
        $q = Invoice::query()->where('client_id', $clientId)->where('status', 'approved');
        $number = trim((string) ($in['invoice_number'] ?? ''));
        if ($number !== '') {
            $q->where('invoice_number', $number);
        }
        $inv = $q->orderByDesc('approved_at')->orderByDesc('id')->first();
        if ($inv === null) {
            return ['error' => 'لا فاتورة معتمدة بهذا الرقم لهذا العميل', 'note' => 'أخبر العميل أنك لم تجد الفاتورة، واطلب منه رقمها كما في الفاتورة، أو حوّله لموظف.'];
        }
        $key = 'wa:bot:resend:'.$conv->id.':'.now()->toDateString();
        Cache::add($key, 0, now()->addDay());
        if ((int) Cache::get($key) >= 3) {
            return ['error' => 'بلغ الحد اليومي لإرسال الفواتير', 'note' => 'أخبر العميل أن الفاتورة أُرسلت له اليوم، وإن لم تصله فسيتابع معه موظف.'];
        }
        try {
            $pdf = InvoicePdfService::render($inv);
        } catch (\Throwable $e) {
            WaLog::error('bot.resend_invoice.render_failed', ['conv_id' => $conv->id, 'exception' => $e]);

            return ['error' => 'تعذّر تجهيز الفاتورة الآن', 'note' => 'اعتذر للعميل واعرض تحويله لموظف.'];
        }
        $res = WhatsAppChannel::send($conv, OutboundMessage::document('local:'.$pdf['path'], InvoicePdfService::filename($inv), InvoicePdfService::defaultCaption($inv)), 'bot', null, [
            'send_key' => 'bot-invoice:'.$conv->id.':'.$inv->id.':'.now()->format('YmdH'),
            'media' => ['mime' => 'application/pdf', 'sha256' => $pdf['sha256'], 'size' => $pdf['size'], 'filename' => InvoicePdfService::filename($inv), 'path' => $pdf['path']],
            'meta' => ['invoice_id' => $inv->id],
        ]);
        if (!$res['ok']) {
            return ['error' => 'تعذّر إرسال الفاتورة', 'note' => 'اعتذر للعميل واعرض تحويله لموظف.'];
        }
        Cache::increment($key);

        return ['sent' => true, 'invoice_number' => $inv->invoice_number, 'note' => 'أُرسلت الفاتورة PDF. أخبره بجملة قصيرة أنها وصلته، ولا تكرر محتواها.'];
    }

    public static function tripDetails(array $in, WaConversation $conv): array
    {
        $clientId = $conv->financialClientId();
        if ($clientId === null) {
            return self::NOT_LINKED;
        }
        $rows = Invoice::query()->with('offer')->where('client_id', $clientId)->where('status', 'approved')
            ->whereNotNull('trip_date')->where('trip_date', '>=', now()->subDays(20)->toDateString())->orderBy('trip_date')->limit(3)->get();

        return [
            'count' => $rows->count(),
            'trips' => $rows->map(fn (Invoice $i) => array_filter([
                'invoice_number' => $i->invoice_number,
                'trip_date' => \Illuminate\Support\Carbon::parse($i->trip_date)->toDateString(),
                'return_date' => $i->offer?->return_date?->toDateString(),
                'destination' => $i->offer?->destination,
                'offer' => $i->offer ? $i->offer->toBotSummary() : null,
                'seats' => $i->offer_seats ?: null,
            ], fn ($v) => $v !== null && $v !== []))->values()->all(),
            'note' => $rows->isEmpty() ? 'لا رحلة قادمة أو جارية مسجلة لهذا العميل. اعرض تحويله لموظف.'
                : 'أجب عن سؤال العميل من هذه البيانات وحدها. ما ليس هنا (أرقام الغرف، مواعيد الحافلات) حوّله لموظف ولا تخمّن.',
        ];
    }

    /** مشكلة يبلّغ عنها العميل أثناء الرحلة: تحويل عاجل يصل الإدارة فوراً، وملاحظة بالملخص */
    public static function reportIssue(array $in, WaConversation $conv): array
    {
        $summary = CustomerNotes::scrub((string) ($in['summary'] ?? ''));
        WaCustomerNote::create(['conversation_id' => $conv->id, 'client_id' => $conv->client_id,
            'note' => '🚨 بلاغ أثناء الرحلة: '.$summary, 'source' => 'bot', 'created_at' => now()]);
        BotTools::escalate($conv, null, HandoffReason::HumanRequest);
        $conv->forceFill(['needs_attention' => true] + (ReplyState::available() ? ['attention_reason' => 'trip_issue'] : []))->save();
        WhatsAppInbox::notifyAdmins('🚨 بلاغ من مسافر', $conv->noticeLabel().' — '.mb_substr($summary, 0, 120), '/whatsapp/inbox?conv='.$conv->id);

        return ['ok' => true, 'note' => 'طمئن العميل بجملة أن البلاغ وصل الإدارة الآن وأن موظفاً سيتواصل معه فوراً، ثم توقّف.'];
    }

    /** إيقاف أو تشغيل رسائل النظام للعميل: كلها أو مجموعة (الفواتير، الرحلات، العروض، المتابعات) */
    public static function notifications(array $in, WaConversation $conv): array
    {
        $clientId = $conv->client_id ? (int) $conv->client_id : null;
        if ($clientId === null) {
            return self::NOT_LINKED;
        }
        $action = (string) ($in['action'] ?? 'status');
        $group = isset($in['group']) && isset(Catalog::GROUPS[$in['group']]) && $in['group'] !== 'admin' ? (string) $in['group'] : null;
        if ($action === 'mute') {
            WaNotificationPref::updateOrCreate(['client_id' => $clientId, 'group' => $group, 'type_key' => null], ['muted_until' => null, 'source' => 'bot']);
        } elseif ($action === 'unmute') {
            WaNotificationPref::where('client_id', $clientId)->when($group !== null, fn ($q) => $q->where('group', $group))->delete();
        }
        $muted = WaNotificationPref::where('client_id', $clientId)->where(fn ($q) => $q->whereNull('muted_until')->orWhere('muted_until', '>', now()))->get(['group', 'type_key']);

        return [
            'ok' => true,
            'muted' => $muted->map(fn ($p) => $p->group ? (Catalog::GROUPS[$p->group] ?? $p->group) : ($p->type_key ? Catalog::label($p->type_key) : 'كل الرسائل'))->values()->all(),
            'note' => match ($action) {
                'mute' => 'أكّد للعميل أنه لن تصله '.($group ? 'رسائل '.Catalog::GROUPS[$group] : 'رسائل النظام الآلية').'، وأن المحادثة معنا تبقى متاحة.',
                'unmute' => 'أكّد للعميل أن الرسائل عادت.',
                default => 'أخبر العميل بما هو موقوف عنده إن وُجد.',
            },
        ];
    }
}
