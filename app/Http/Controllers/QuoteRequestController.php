<?php

namespace App\Http\Controllers;

use App\Models\QuoteRequest;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/**
 * طلبات التسعير وتأكيد الحجز التي ينشئها البوت.
 * هنا يضع الموظف السعر النهائي ويرسله للعميل ويُتمّ الحجز.
 */
class QuoteRequestController extends Controller
{
    /**
     * «من → إلى» بأمان.
     *
     * ⚠️ لا تستخدم trim($s, ' →') هنا: قائمة المحارف في trim تعمل على البايتات
     * لا على المحارف، و«→» = E2 86 92، فكان يقتطع البايت 86 من آخر أي نصّ
     * ينتهي بحرف يحمله — و«ن» = D9 86 — فيبقى D9 معلّقاً ويتلف ترميز UTF-8
     * ويُسقط الصفحة كلها عند تحويل الردّ إلى JSON.
     */
    private static function route(?string $from, ?string $to): string
    {
        return implode(' → ', array_filter([trim((string) $from), trim((string) $to)], 'strlen'));
    }

    /** «فندق مكة: رحاب الصفوة · ثنائية × 2» — من offer_selection إن وُجد */
    private static function selectionText(QuoteRequest $r): ?string
    {
        $sel = $r->offer_selection;
        if (!$r->offer || !is_array($sel) || !isset($sel['sel'])) {
            return null;
        }
        $labels = collect(\App\Services\OfferPricing::selectionLabels($r->offer, (array) $sel['sel']))
            ->pluck('option')->implode(' · ');
        $room = $sel['room'] ? (\App\Models\OfferOption::ROOM_TYPES[$sel['room']] ?? null) : null;

        return trim(($labels ?: $r->offer->title) . ($room ? " · {$room}" : '') . ' × ' . ($sel['pax'] ?? $r->pax()));
    }

    public function index(Request $request)
    {
        abort_unless(auth()->user()->can('quotes.view'), 403);

        $status = $request->get('status', 'open');

        $me = auth()->user();

        $requests = QuoteRequest::query()
            ->visibleTo($me)
            ->with(['client:id,name,code', 'offer.groups.options.stays', 'quoter:id,name', 'conversation:id,phone,display_name,bot_enabled,last_inbound_at'])
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['new', 'priced']))
            ->when(in_array($status, ['new', 'priced', 'sent', 'won', 'lost', 'cancelled'], true),
                fn ($q) => $q->where('status', $status))
            ->when($request->search, fn ($q, $s) => $q->where(function ($w) use ($s) {
                $w->where('request_number', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%")
                  ->orWhere('customer_name', 'like', "%{$s}%");
            }))
            ->orderByRaw("CASE status WHEN 'new' THEN 0 WHEN 'priced' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->paginate(20)->withQueryString();

        $requests->getCollection()->transform(fn ($r) => [
            'id' => $r->id,
            'request_number' => $r->request_number,
            'type' => $r->type,
            'status' => $r->status,
            'phone' => $r->phone ?: ($r->conversation?->phone ? '+'.$r->conversation->phone : ''),
            'customer' => $r->client?->name ?: ($r->customer_name ?: ($r->phone ?: '—')),
            // عميل بلا رقم (E11): الرقم يُطلب من المحادثة بزر «طلب الرقم»
            'phoneless' => $r->phone === '' && !$r->conversation?->phone,
            'client' => $r->client?->name,
            'offer' => $r->offer?->title,
            'route' => self::route($r->route_from, $r->route_to),
            'depart_date' => $r->depart_date?->toDateString(),
            'return_date' => $r->return_date?->toDateString(),
            'pax' => $r->pax(),
            'pax_detail' => "{$r->pax_adults}+{$r->pax_children}+{$r->pax_infants}",
            'details' => $r->details,
            'quoted_price_jod' => $r->quoted_price_jod !== null ? (float) $r->quoted_price_jod : null,
            'quoted_note' => $r->quoted_note,
            'quoted_by' => $r->quoter?->name,
            'quoted_at' => $r->quoted_at?->format('Y-m-d H:i'),
            'created_at' => $r->created_at?->format('Y-m-d H:i'),
            'conversation_id' => $r->conversation_id,
            'window_open' => $r->conversation?->isWindowOpen() ?? false,
            // طلب حجز عرض: اختيار العميل منظّماً من حمولة البوت، والتحويل لفاتورة عرض
            'offer_id' => $r->offer_id,
            'selection' => self::selectionText($r),
            'invoice_id' => $r->invoice_id,
            'invoice_number' => $r->invoice_id ? \App\Models\Invoice::whereKey($r->invoice_id)->value('invoice_number') : null,
            'can_convert' => $r->offer_id && !$r->invoice_id && !in_array($r->status, ['lost', 'cancelled'], true)
                && auth()->user()->can('invoices.create'),
        ]);

        return Inertia::render('QuoteRequests/Index', [
            'title' => 'طلبات التسعير والحجز',
            'requests' => $requests,
            'filters' => ['status' => $status, 'search' => $request->search],
            'counts' => [
                'new' => QuoteRequest::query()->visibleTo($me)->where('status', 'new')->count(),
                'priced' => QuoteRequest::query()->visibleTo($me)->where('status', 'priced')->count(),
            ],
            'canReply' => auth()->user()->can('whatsapp.reply'),
        ]);
    }

    /** الموظف يضع السعر النهائي — واختيارياً يرسله للعميل على واتساب */
    public function price(Request $request, QuoteRequest $quoteRequest)
    {
        abort_unless(auth()->user()->can('quotes.price'), 403);

        $data = $request->validate([
            'quoted_price_jod' => 'required|numeric|min:0',
            'quoted_note' => 'nullable|string|max:1000',
            'send_to_client' => 'boolean',
            'confirm_resend' => 'boolean',
        ]);

        // موظفان يسعّران الطلب نفسه معاً: الثاني ينتظر بدل أن يصل العميل سعران
        $lock = Cache::lock("quote:{$quoteRequest->id}", 30);
        if (!$lock->get()) {
            return back()->with('error', 'زميل يسعّر هذا الطلب الآن. انتظر لحظة ثم حدّث الصفحة.');
        }

        try {
            return $this->priceLocked($request, $quoteRequest->fresh(), $data);
        } finally {
            $lock->release();
        }
    }

    private function priceLocked(Request $request, QuoteRequest $quoteRequest, array $data)
    {
        // أُرسل سعر لهذا الطلب بعد فتح النموذج: لا إرسال ثانٍ للعميل دون تأكيد صريح
        if (!empty($data['send_to_client']) && $quoteRequest->status === 'sent' && empty($data['confirm_resend'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'confirm_resend' => 'أُرسل سعر لهذا الطلب للعميل بالفعل. أكّد إعادة الإرسال.',
            ]);
        }

        $quoteRequest->update([
            'quoted_price_jod' => $data['quoted_price_jod'],
            'quoted_note' => $data['quoted_note'] ?? null,
            'quoted_by' => auth()->id(),
            'quoted_at' => now(),
            'status' => 'priced',
        ]);

        // الإرسال للعميل: نصّ يبنيه الكود — الرقم من الموظف لا من أي نموذج
        if (!empty($data['send_to_client']) && $quoteRequest->conversation) {
            $price = number_format((float) $data['quoted_price_jod'], 3);
            $lines = ["عرض السعر لطلبك {$quoteRequest->request_number}:"];
            if ($quoteRequest->offer) {
                $lines[] = $quoteRequest->offer->title;
            } elseif ($quoteRequest->route_from || $quoteRequest->route_to) {
                $lines[] = self::route($quoteRequest->route_from, $quoteRequest->route_to)
                    . ($quoteRequest->depart_date ? ' — ' . $quoteRequest->depart_date->toDateString() : '');
            }
            $lines[] = "الإجمالي: {$price} د.أ لعدد {$quoteRequest->pax()} مسافر";
            if (!empty($data['quoted_note'])) {
                $lines[] = $data['quoted_note'];
            }
            $lines[] = 'للتأكيد أو أي استفسار، نحن معك هنا.';

            // رسالة يبنيها الكود (context=code): تحذيرات السياسة تُسجَّل ولا تطلب تأكيداً (E15.9)
            // المفتاح (E19): السعر والملاحظة نفسيهما خلال 10 دقائق لا يُرسلان مرتين، و«أعد الإرسال» الصريح يرسل
            $key = \App\Services\WhatsApp\SendKey::quote($quoteRequest, $data['quoted_price_jod'], $data['quoted_note'] ?? null,
                !empty($data['confirm_resend']) ? \Illuminate\Support\Str::random(8) : null);
            $res = WhatsAppChannel::sendText($quoteRequest->conversation, implode("\n", $lines), 'staff', auth()->id(), 'code', $key);
            self::auditPrice($quoteRequest, $data, $res['ok']);

            if (!empty($res['replayed'])) {
                $quoteRequest->update(['status' => 'sent']);

                return back()->with('success', 'أُرسل هذا السعر نفسه للعميل قبل قليل — لم يُرسل مرة ثانية.');
            }
            // مجهول النتيجة (E19)، ومع connection_resilience (E28) المهلة والانقطاع وقيد الإرسال أيضاً: لا «تعذّر» لما قد يكون خرج
            if (!empty($res['unknown']) || (\App\Services\WhatsApp\SendOutcome::unknown($res) && \App\Services\WhatsApp\SendOutcome::enabled())) {
                return back()->with('error', 'حُفظ السعر، وقد يكون أُرسل — تحقق من المحادثة قبل إعادة الإرسال.');
            }
            if ($res['ok']) {
                $quoteRequest->update(['status' => 'sent']);
            } else {
                // نص عربي من جدول الرموز (E18) مع الإجراء إن وُجد، لا نص ميتا الخام
                return back()->with('error', 'حُفظ السعر لكن تعذّر إرساله: ' . $res['error'] . (!empty($res['action']) ? ' — ' . $res['action'] : ''));
            }
        } else {
            self::auditPrice($quoteRequest, $data, null);
        }

        return back()->with('success', 'تم حفظ السعر' . (!empty($data['send_to_client']) ? ' وإرساله للعميل' : ''));
    }

    /** السعر وهل أُرسل، بلا نص الرسالة ولا ملاحظة العميل */
    private static function auditPrice(QuoteRequest $quoteRequest, array $data, ?bool $sentOk): void
    {
        \App\Models\AuditLog::safe(\App\Enums\AuditAction::QuotePrice, 'quote_request', $quoteRequest->id, $quoteRequest->request_number, [
            'quoted_price_jod' => (float) $data['quoted_price_jod'], 'send_to_client' => !empty($data['send_to_client']), 'sent_ok' => $sentOk,
        ]);
    }

    public function updateStatus(Request $request, QuoteRequest $quoteRequest)
    {
        abort_unless(auth()->user()->can('quotes.price'), 403);

        $data = $request->validate(['status' => 'required|in:new,priced,sent,won,lost,cancelled']);
        $from = $quoteRequest->status;
        $quoteRequest->update($data);
        \App\Models\AuditLog::safe(\App\Enums\AuditAction::QuoteStatus, 'quote_request', $quoteRequest->id, $quoteRequest->request_number, ['from' => $from, 'to' => $data['status']]);

        return back()->with('success', 'تم تحديث حالة الطلب');
    }
}
