<?php

namespace App\Http\Controllers;

use App\Models\QuoteRequest;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Http\Request;
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

    public function index(Request $request)
    {
        abort_unless(auth()->user()->can('quotes.view'), 403);

        $status = $request->get('status', 'open');

        $requests = QuoteRequest::query()
            ->with(['client:id,name,code', 'offer:id,title', 'quoter:id,name', 'conversation:id,phone,display_name,bot_enabled,last_inbound_at'])
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
            'phone' => $r->phone,
            'customer' => $r->customer_name ?: $r->client?->name ?: $r->phone,
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
        ]);

        return Inertia::render('QuoteRequests/Index', [
            'title' => 'طلبات التسعير والحجز',
            'requests' => $requests,
            'filters' => ['status' => $status, 'search' => $request->search],
            'counts' => [
                'new' => QuoteRequest::where('status', 'new')->count(),
                'priced' => QuoteRequest::where('status', 'priced')->count(),
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
        ]);

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

            $res = WhatsAppChannel::sendText($quoteRequest->conversation, implode("\n", $lines), 'staff', auth()->id());

            if ($res['ok']) {
                $quoteRequest->update(['status' => 'sent']);
            } else {
                return back()->with('error', 'حُفظ السعر لكن تعذّر إرساله: ' . $res['error']);
            }
        }

        return back()->with('success', 'تم حفظ السعر' . (!empty($data['send_to_client']) ? ' وإرساله للعميل' : ''));
    }

    public function updateStatus(Request $request, QuoteRequest $quoteRequest)
    {
        abort_unless(auth()->user()->can('quotes.price'), 403);

        $data = $request->validate(['status' => 'required|in:new,priced,sent,won,lost,cancelled']);
        $quoteRequest->update($data);

        return back()->with('success', 'تم تحديث حالة الطلب');
    }
}
