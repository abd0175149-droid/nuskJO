<?php

namespace App\Services\WhatsApp;

use App\Models\Invoice;
use App\Models\Offer;
use App\Models\QuoteRequest;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaCustomerNote;
use App\Services\TravelersReport;

/**
 * أدوات المجال — الحدّ الفاصل بين البوت ونظام نُسك.
 *
 * قواعد ثابتة:
 *  • كل هويّة تأتي من المحادثة ($conv) لا من وسائط النموذج — فلا يرى عميل بيانات غيره.
 *  • كل رقم مالي يُحسب بالكود ويُمرَّر جاهزاً — النموذج لا يحسب.
 *  • البوت لا يكتب في دفتر الأستاذ ولا يعتمد شيئاً — أقصى ما يفعله إنشاء «طلب» وتحويل المحادثة.
 */
class BotTools
{
    /** إعلانات الأدوات بصيغة Anthropic tool_use */
    public static function declarations(WaBotSetting $s, WaConversation $conv): array
    {
        $all = [
            [
                'name' => 'get_offers',
                'description' => 'اجلب قائمة العروض والباقات المتاحة حالياً (عمرة، حج، تذاكر، فنادق، تأشيرات...). استدعِها عندما يسأل العميل عن العروض أو الأسعار أو ما هو متاح. اعرض النتائج بإيجاز ولا تخترع عرضاً غير موجود.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => ['type' => 'string', 'description' => 'تصنيف اختياري: umrah|hajj|flight|visa|hotel|transport|tour|package'],
                    ],
                ],
            ],
            [
                'name' => 'get_offer_details',
                'description' => 'تفاصيل عرض محدد برقمه (يشمل ما يشمله وما لا يشمله والفندق والتواريخ). استدعِها بعد أن يختار العميل عرضاً من القائمة.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['offer_id' => ['type' => 'integer', 'description' => 'رقم العرض']],
                    'required' => ['offer_id'],
                ],
            ],
            [
                'name' => 'get_my_balance',
                'description' => 'رصيد ذمّة العميل الحالي (كم عليه من مبالغ). تعمل فقط إن كان رقم المحادثة مربوطاً بحساب عميل مسجّل.',
                'input_schema' => ['type' => 'object', 'properties' => []],
            ],
            [
                'name' => 'get_my_invoices',
                'description' => 'فواتير العميل الحالي المعتمدة مع المبلغ المتبقي على كل فاتورة. استدعِها إذا سأل عن فواتيره أو ما تبقّى عليه.',
                'input_schema' => ['type' => 'object', 'properties' => []],
            ],
            [
                'name' => 'get_my_trips',
                'description' => 'رحلات العميل الحالي القادمة (تواريخ السفر المسجّلة على فواتيره المعتمدة).',
                'input_schema' => ['type' => 'object', 'properties' => []],
            ],
            [
                'name' => 'request_quote',
                'description' => 'أنشئ طلب تسعير وحوّل المحادثة لموظف. استدعِها عندما يطلب العميل سعراً نهائياً لرحلة طيران أو خدمة، أو يسأل عن توفّر المقاعد. اجمع أولاً بالحوار: المسار (من/إلى) وتاريخ السفر وعدد المسافرين. لا تذكر أي سعر من عندك أبداً — الموظف هو من يسعّر.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'description' => 'flight|package|visa|hotel|transport|other'],
                        'route_from' => ['type' => 'string', 'description' => 'مدينة/مطار المغادرة'],
                        'route_to' => ['type' => 'string', 'description' => 'مدينة/مطار الوصول'],
                        'depart_date' => ['type' => 'string', 'description' => 'تاريخ السفر YYYY-MM-DD'],
                        'return_date' => ['type' => 'string', 'description' => 'تاريخ العودة إن وُجد YYYY-MM-DD'],
                        'adults' => ['type' => 'integer'],
                        'children' => ['type' => 'integer'],
                        'infants' => ['type' => 'integer'],
                        'details' => ['type' => 'string', 'description' => 'ملخّص ما طلبه العميل بكلماته'],
                    ],
                    'required' => ['type', 'details'],
                ],
            ],
            [
                'name' => 'confirm_booking',
                'description' => 'العميل يريد تأكيد حجز عرض معيّن. استدعِها عند موافقته الصريحة على الحجز. تُنشئ طلب حجز وتحوّل المحادثة لموظف ليُتمّه. لا تؤكّد للعميل أنّ الحجز مكتمل — قل إنّ موظفاً سيتواصل لإتمامه.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'offer_id' => ['type' => 'integer', 'description' => 'رقم العرض المطلوب حجزه'],
                        'adults' => ['type' => 'integer'],
                        'children' => ['type' => 'integer'],
                        'infants' => ['type' => 'integer'],
                        'details' => ['type' => 'string', 'description' => 'أي تفاصيل ذكرها العميل (أسماء، ملاحظات، تواريخ مفضّلة)'],
                    ],
                    'required' => ['offer_id', 'details'],
                ],
            ],
            [
                'name' => 'save_note',
                'description' => 'احفظ ملاحظة مهمة عن العميل لتذكّرها لاحقاً (تفضيلاته، عدد أفراد عائلته، اسمه). لا تحفظ أسراراً ولا أرقام بطاقات.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['note' => ['type' => 'string', 'description' => 'الملاحظة — 500 حرف كحدّ أقصى']],
                    'required' => ['note'],
                ],
            ],
            [
                'name' => 'handoff_to_human',
                'description' => 'حوّل المحادثة لموظف بشري. استدعِها إذا طلب العميل التحدث مع شخص، أو كان غاضباً أو يشكو، أو كان طلبه خارج قدراتك.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['reason' => ['type' => 'string', 'description' => 'سبب التحويل بإيجاز']],
                    'required' => ['reason'],
                ],
            ],
        ];

        return array_values(array_filter($all, fn ($t) => $s->toolEnabled($t['name'])));
    }

    // ==================== التنفيذ ====================

    public static function execute(string $name, array $in, WaConversation $conv): array
    {
        return match ($name) {
            'get_offers' => self::getOffers($in),
            'get_offer_details' => self::getOfferDetails($in),
            'get_my_balance' => self::getBalance($conv),
            'get_my_invoices' => self::getInvoices($conv),
            'get_my_trips' => self::getTrips($conv),
            'request_quote' => self::requestQuote($in, $conv),
            'confirm_booking' => self::confirmBooking($in, $conv),
            'save_note' => self::saveNote($in, $conv),
            'handoff_to_human' => self::handoff($in, $conv),
            default => ['error' => 'أداة غير معروفة', 'note' => 'اعتذر للعميل بإيجاز.'],
        };
    }

    private static function getOffers(array $in): array
    {
        $q = Offer::forBot()->orderBy('sort_order')->orderBy('price_jod');
        if (!empty($in['category'])) {
            $q->where('category', $in['category']);
        }
        $offers = $q->limit(12)->get()->map(fn ($o) => $o->toBotArray())->all();

        return [
            'count' => count($offers),
            'offers' => $offers,
            'note' => $offers
                ? 'اعرض العروض بإيجاز (الاسم والسعر والمدة). لا تخترع تفاصيل غير مذكورة. اسأل العميل أيّ عرض يهمّه.'
                : 'لا توجد عروض منشورة حالياً — اعتذر واسأل العميل عمّا يبحث عنه بالتحديد، ثم استدعِ request_quote.',
        ];
    }

    private static function getOfferDetails(array $in): array
    {
        $o = Offer::forBot()->find($in['offer_id'] ?? 0);
        if (!$o) {
            return ['error' => 'العرض غير متاح', 'note' => 'أخبر العميل أنّ هذا العرض غير متاح حالياً واعرض عليه البدائل.'];
        }

        return [
            'offer' => $o->toBotArray(),
            'note' => 'اعرض التفاصيل بإيجاز. إن أراد الحجز فاستدعِ confirm_booking.',
        ];
    }

    private static function getBalance(WaConversation $conv): array
    {
        if (!$conv->client_id) {
            return self::notLinked();
        }
        $client = $conv->client;
        $balance = round((float) ($client?->account?->balance ?? 0), 3);

        return [
            'client' => $client?->name,
            'balance_jod' => $balance,
            'note' => $balance > 0
                ? 'أبلغ العميل بالمبلغ المتبقي عليه كما هو — لا تحسب ولا تقرّب.'
                : 'أبلغه أنّ ذمّته مسدّدة ولا يوجد مبلغ متبقٍ.',
        ];
    }

    private static function getInvoices(WaConversation $conv): array
    {
        if (!$conv->client_id) {
            return self::notLinked();
        }

        $invoices = Invoice::where('client_id', $conv->client_id)
            ->where('status', 'approved')
            ->orderByDesc('invoice_date')->limit(10)
            ->get(['id', 'invoice_number', 'invoice_date', 'trip_date', 'total_sell_jod']);

        $remaining = TravelersReport::remainingFifo(collect([$conv->client_id]));

        $rows = $invoices->map(fn ($i) => [
            'invoice_number' => $i->invoice_number,
            'date' => $i->invoice_date?->toDateString(),
            'trip_date' => $i->trip_date?->toDateString(),
            'total_jod' => round((float) $i->total_sell_jod, 3),
            'remaining_jod' => $remaining[$i->id] ?? round((float) $i->total_sell_jod, 3),
        ])->all();

        return [
            'count' => count($rows),
            'invoices' => $rows,
            'note' => 'اذكر الأرقام كما وردت حرفياً. المتبقي محسوب بالنظام — لا تعد حسابه.',
        ];
    }

    private static function getTrips(WaConversation $conv): array
    {
        if (!$conv->client_id) {
            return self::notLinked();
        }

        $trips = Invoice::where('client_id', $conv->client_id)
            ->where('status', 'approved')
            ->whereNotNull('trip_date')
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->orderBy('trip_date')->limit(10)
            ->get(['invoice_number', 'trip_date'])
            ->map(fn ($i) => ['invoice_number' => $i->invoice_number, 'trip_date' => $i->trip_date?->toDateString()])
            ->all();

        return [
            'count' => count($trips),
            'trips' => $trips,
            'note' => $trips ? 'اذكر التواريخ كما هي.' : 'لا رحلات قادمة مسجّلة — اسأله إن كان يريد حجز رحلة جديدة.',
        ];
    }

    /** ★ طلب تسعير → إشعار الموظف + تحويل المحادثة */
    private static function requestQuote(array $in, WaConversation $conv): array
    {
        $qr = QuoteRequest::create([
            'request_number' => QuoteRequest::nextNumber(),
            'conversation_id' => $conv->id,
            'client_id' => $conv->client_id,
            'phone' => $conv->phone,
            'customer_name' => $conv->display_name,
            'type' => $in['type'] ?? 'flight',
            'route_from' => mb_substr((string) ($in['route_from'] ?? ''), 0, 8) ?: null,
            'route_to' => mb_substr((string) ($in['route_to'] ?? ''), 0, 8) ?: null,
            'depart_date' => self::date($in['depart_date'] ?? null),
            'return_date' => self::date($in['return_date'] ?? null),
            'pax_adults' => max(1, (int) ($in['adults'] ?? 1)),
            'pax_children' => max(0, (int) ($in['children'] ?? 0)),
            'pax_infants' => max(0, (int) ($in['infants'] ?? 0)),
            'details' => mb_substr((string) ($in['details'] ?? ''), 0, 2000),
            'status' => 'new',
        ]);

        self::escalate($conv, $qr, '💰 طلب تسعير جديد');

        return [
            'request_number' => $qr->request_number,
            'note' => 'أبلغ العميل أنّ طلبه وصل لفريقنا وسيعود إليه موظف بالسعر النهائي وتأكيد التوفّر قريباً. '
                . 'لا تذكر أي سعر ولا تؤكّد توفّر مقعد. جملة واحدة قصيرة تكفي.',
        ];
    }

    /** ★ تأكيد حجز عرض → إشعار الموظف + تحويل المحادثة */
    private static function confirmBooking(array $in, WaConversation $conv): array
    {
        $offer = Offer::forBot()->find($in['offer_id'] ?? 0);
        if (!$offer) {
            return ['error' => 'العرض غير متاح', 'note' => 'أخبر العميل أنّ العرض غير متاح واعرض البدائل.'];
        }

        $qr = QuoteRequest::create([
            'request_number' => QuoteRequest::nextNumber(),
            'conversation_id' => $conv->id,
            'client_id' => $conv->client_id,
            'offer_id' => $offer->id,
            'phone' => $conv->phone,
            'customer_name' => $conv->display_name,
            'type' => 'package',
            'depart_date' => $offer->departure_date,
            'return_date' => $offer->return_date,
            'pax_adults' => max(1, (int) ($in['adults'] ?? 1)),
            'pax_children' => max(0, (int) ($in['children'] ?? 0)),
            'pax_infants' => max(0, (int) ($in['infants'] ?? 0)),
            'details' => 'طلب حجز عرض: ' . $offer->title . ' — ' . mb_substr((string) ($in['details'] ?? ''), 0, 1500),
            'status' => 'new',
        ]);

        self::escalate($conv, $qr, '🧾 طلب تأكيد حجز');

        return [
            'request_number' => $qr->request_number,
            'offer' => $offer->title,
            'note' => 'أبلغ العميل أنّ طلب الحجز وصل وأنّ موظفاً سيتواصل معه لإتمامه وتأكيد التوفّر والدفع. '
                . 'لا تقل إنّ الحجز تمّ أو تأكّد. جملة قصيرة فقط.',
        ];
    }

    /**
     * التحويل الكامل: إيقاف البوت + شارة تدخّل + إشعار الأدمن وموظف العميل المسؤول.
     */
    private static function escalate(WaConversation $conv, ?QuoteRequest $qr, string $title): void
    {
        $assignee = null;
        // موظف العميل المسؤول إن وُجد
        if ($conv->client_id && ($emp = $conv->client?->employee_id)) {
            $assignee = \App\Models\Employee::with('user:id,name')->find($emp)?->user_id;
        }

        $conv->update([
            'bot_enabled' => false,          // تحويل كامل — لا يردّ البوت بعدها
            'needs_attention' => true,
            'assigned_to' => $assignee,
        ]);

        if ($qr && $assignee) {
            $qr->update(['assigned_to' => $assignee]);
        }

        $who = $conv->display_name ?: $conv->phone;
        $body = $qr
            ? "{$who} — طلب {$qr->request_number}: " . mb_substr((string) $qr->details, 0, 140)
            : "{$who} — يحتاج تدخّلاً بشرياً.";
        $url = $qr ? '/quote-requests' : '/whatsapp/inbox';

        WhatsAppInbox::notifyAdmins($title, $body, $url);

        if ($assignee) {
            try {
                \App\Services\NotificationService::send($assignee, $title, $body, [
                    'type' => 'whatsapp', 'icon' => '💬', 'action_url' => $url,
                ]);
            } catch (\Throwable $e) {
                // الإشعار ليس حرجاً — الطلب محفوظ والشارة مرفوعة
            }
        }
    }

    private static function saveNote(array $in, WaConversation $conv): array
    {
        WaCustomerNote::create([
            'conversation_id' => $conv->id,
            'client_id' => $conv->client_id,
            'note' => mb_substr((string) ($in['note'] ?? ''), 0, 500),
            'source' => 'bot',
            'created_at' => now(),
        ]);

        return ['saved' => true, 'note' => 'لا تذكر للعميل أنك حفظت ملاحظة — تابع الحديث طبيعياً.'];
    }

    private static function handoff(array $in, WaConversation $conv): array
    {
        self::escalate($conv, null, '🙋 تحويل محادثة واتساب');

        return [
            'ok' => true,
            'note' => 'أبلغ العميل بلطف أنّ موظفاً سيتواصل معه حالاً، ثم توقّف عن الردّ.',
        ];
    }

    private static function notLinked(): array
    {
        return [
            'linked' => false,
            'note' => 'رقم العميل غير مربوط بحساب في النظام. لا تكشف أي بيانات مالية. '
                . 'اعتذر بلطف واقترح أن يتواصل معه موظف للتحقق، أو استدعِ handoff_to_human.',
        ];
    }

    private static function date(?string $d): ?string
    {
        if (!$d) return null;
        try {
            return \Illuminate\Support\Carbon::parse($d)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
