<?php

namespace App\Services\WhatsApp;

use App\Models\Invoice;
use App\Models\Offer;
use App\Models\QuoteRequest;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaCustomerNote;
use App\Services\OfferCardService;
use App\Services\TravelersReport;
use Illuminate\Support\Facades\Storage;

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
    /** أسماء أدوات بوت العملاء كما تظهر في الإعدادات وفي أداة «إدارة الأدوات» */
    public const LABELS = [
        'get_offers' => 'عرض العروض والباقات',
        'get_offer_details' => 'تفاصيل عرض',
        'send_offer_card' => 'إرسال صورة بطاقة العرض',
        'set_topic' => 'تحديد موضوع المحادثة وتوجيهها',
        'get_my_balance' => 'رصيد ذمّة العميل',
        'get_my_invoices' => 'فواتير العميل والمتبقي',
        'get_my_trips' => 'رحلات العميل القادمة',
        'request_quote' => 'طلب تسعير ← تحويل لموظف',
        'confirm_booking' => 'تأكيد حجز ← تحويل لموظف',
        'save_note' => 'حفظ ملاحظة عن العميل',
        'handoff_to_human' => 'تحويل لموظف',
    ];

    /** إعلانات الأدوات بصيغة Anthropic tool_use */
    public static function declarations(WaBotSetting $s, WaConversation $conv): array
    {
        $all = [
            [
                'name' => 'get_offers',
                'description' => 'اجلب قائمة العروض والباقات المتاحة حالياً (عمرة، حج، تذاكر، فنادق، تأشيرات...). '
                    . 'استدعِها عندما يسأل العميل عن العروض أو الأسعار أو ما هو متاح. اعرض النتائج بإيجاز باسم العرض و«يبدأ من» ولا تخترع عرضاً غير موجود. '
                    . 'انتبه: شكل العرض يختلف بتصنيفه — عروض الفنادق تأتي بخيارات لكل خيار أسعار للفرد حسب سعة الغرفة، '
                    . 'وعمرة «مكة والمدينة» يشمل خيارها فندقين بسعر واحد لهما معاً، والتأشيرات لها سعر واحد للفرد بلا فنادق. '
                    . 'التزم دائماً بحقل pricing_note المرافق لكل عرض ولا تصف التسعير بغيره.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => ['type' => 'string', 'description' => 'تصنيف اختياري: umrah|hajj|flight|visa|hotel|transport|tour|package'],
                    ],
                ],
            ],
            [
                'name' => 'get_offer_details',
                'description' => 'تفاصيل عرض محدد برقمه: ما يشمله وما لا يشمله وخياراته وأسعارها، أو شروط التأشيرة ومدّة إصدارها إن كان تأشيرة. '
                    . 'استدعِها بعد أن يختار العميل عرضاً من القائمة. اذكر السعر دائماً بوصفه «للفرد» وأنّه غير نهائي ويحتاج تأكيد الموظف.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['offer_id' => ['type' => 'integer', 'description' => 'رقم العرض']],
                    'required' => ['offer_id'],
                ],
            ],
            [
                'name' => 'send_offer_card',
                'description' => 'أرسل للعميل صورة بطاقة العرض (تحوي الفنادق والأسعار حسب سعة الغرفة وما يشمله العرض). '
                    . 'استدعِها بعد أن يختار العميل عرضاً محدّداً أو يطلب تفاصيله أو صورته أو «التفاصيل كاملة». '
                    . 'أرسل البطاقة مرّة واحدة لكل عرض في المحادثة، ولا تصف محتوى الصورة بعدها — جملة قصيرة تكفي.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['offer_id' => ['type' => 'integer', 'description' => 'رقم العرض']],
                    'required' => ['offer_id'],
                ],
            ],
            [
                'name' => 'set_topic',
                'description' => 'سجّل موضوع المحادثة فور اتّضاحه — حتى قبل أي تحويل لموظف. '
                    . 'هذا يربط المحادثة بالموظف المختصّ فيتابعها من البداية. '
                    . 'استدعِها مرّة واحدة لكل موضوع، وإن تعدّدت اهتمامات العميل سجّلها كلها. '
                    . 'لا تُخبر العميل بذلك ولا تذكره في ردّك إطلاقاً.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'topics' => [
                            'type' => 'array',
                            'description' => 'موضوع أو أكثر من: ' . implode(' | ', array_keys(\App\Services\WhatsApp\TopicRouter::TOPICS)),
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['topics'],
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
                        'option_ref' => ['type' => 'string', 'description' => 'قيمة ref للخيار الذي اختاره العميل من options في get_offers أو get_offer_details — انسخها كما هي'],
                        'supplement_refs' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'قيم ref للإضافات التي طلبها العميل من supplements (مثل باص VIP) — اتركها فارغة إن لم يطلب إضافة'],
                        'hotel' => ['type' => 'string', 'description' => 'الخيار الذي اختاره العميل: اسم الفندق، أو الفندقين معاً في عمرة مكة والمدينة'],
                        'room_type' => ['type' => 'string', 'description' => 'سعة الغرفة المطلوبة: مفردة|ثنائية|ثلاثية|رباعية'],
                        'adults' => ['type' => 'integer'],
                        'children' => ['type' => 'integer'],
                        'infants' => ['type' => 'integer'],
                        'travel_date' => ['type' => 'string', 'description' => 'تاريخ السفر المطلوب إن ذكره العميل YYYY-MM-DD'],
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
            'get_offers' => self::getOffers($in, $conv),
            'get_offer_details' => self::getOfferDetails($in, $conv),
            'send_offer_card' => self::sendOfferCard($in, $conv),
            'set_topic' => self::setTopic($in, $conv),
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

    private static function getOffers(array $in, WaConversation $conv): array
    {
        $q = Offer::forBot()->with(['groups.options.stays', 'options.stays'])->orderBy('sort_order')->orderByDesc('id');
        if (!empty($in['category'])) {
            $q->where('category', $in['category']);
            // تصفّح تصنيف بعينه دليل كافٍ على الموضوع — نسم المحادثة بلا انتظار
            if ($t = TopicRouter::fromOfferCategory($in['category'])) {
                TopicRouter::tag($conv, $t, 'bot');
            }
        }
        // الترتيب النهائي بـ «يبدأ من» يجري في PHP لأنّ السعر صار داخل الفنادق
        $offers = $q->limit(12)->get()
            ->sortBy(fn ($o) => $o->priceFrom() ?? INF)
            ->map(fn ($o) => $o->toBotArray())->values()->all();

        return [
            'count' => count($offers),
            'offers' => $offers,
            'note' => $offers
                ? 'اعرض العروض بإيجاز (الاسم والسعر والمدة، وتاريخ الانطلاق إن وُجد trip_date). لا تخترع تفاصيل غير مذكورة. '
                    . 'availability تقريبية: قلها كما هي («متوفر» أو «مقاعد محدودة») ولا تذكر عدد مقاعد ولا تؤكّد مقعداً. اسأل العميل أيّ عرض يهمّه.'
                : 'لا توجد عروض منشورة حالياً — اعتذر واسأل العميل عمّا يبحث عنه بالتحديد، ثم استدعِ request_quote.',
        ];
    }

    private static function getOfferDetails(array $in, WaConversation $conv): array
    {
        $o = Offer::forBot()->with(['groups.options.stays', 'options.stays'])->find($in['offer_id'] ?? 0);
        if (!$o) {
            return ['error' => 'العرض غير متاح', 'note' => 'أخبر العميل أنّ هذا العرض غير متاح حالياً واعرض عليه البدائل.'];
        }

        if ($t = TopicRouter::fromOfferCategory($o->category)) {
            TopicRouter::tag($conv, $t, 'bot');
        }

        return [
            'offer' => $o->toBotArray(),
            'note' => 'اعرض التفاصيل بإيجاز. إن أراد الحجز فاستدعِ confirm_booking ومرّر ref الخيار الذي اختاره و ref أي إضافة طلبها من supplements. '
                . 'لا تذكر عدد مقاعد ولا تؤكّد توفّراً غير ما في availability.',
        ];
    }

    /** تسجيل موضوع المحادثة — يربطها بالموظف المختصّ قبل أي تحويل */
    private static function setTopic(array $in, WaConversation $conv): array
    {
        $raw = $in['topics'] ?? [];
        if (is_string($raw)) {
            $raw = [$raw];
        }

        $added = [];
        foreach (array_slice((array) $raw, 0, 4) as $topic) {
            $topic = trim((string) $topic);
            if (TopicRouter::tag($conv, $topic, 'bot')) {
                $added[] = TopicRouter::label($topic);
            }
        }

        return [
            'ok' => true,
            'tagged' => $added,
            'note' => 'سُجّل داخلياً. لا تذكر هذا للعميل إطلاقاً وتابع الحوار طبيعياً.',
        ];
    }

    /** إرسال بطاقة العرض كصورة — تُولَّد عند الطلب إن لم تكن جاهزة */
    private static function sendOfferCard(array $in, WaConversation $conv): array
    {
        $offer = Offer::forBot()->with(['groups.options.stays', 'options.stays'])->find($in['offer_id'] ?? 0);
        if (!$offer) {
            return ['error' => 'العرض غير متاح', 'note' => 'أخبر العميل أنّ العرض غير متاح واعرض البدائل.'];
        }

        $path = $offer->cardImage();

        // البطاقة غير موجودة أو قديمة بعد تعديل الأسعار → نولّدها الآن
        if (!$path || $offer->cardIsStale()) {
            try {
                $path = OfferCardService::generate($offer);
            } catch (\Throwable $e) {
                WaLog::error('bot.card.render_failed', ['offer_id' => $offer->id, 'conv_id' => $conv->id, 'exception' => $e]);

                return [
                    'sent' => false,
                    'note' => 'تعذّر إرسال الصورة. اذكر تفاصيل العرض وأسعاره نصّاً بإيجاز بدلاً منها، ولا تعتذر عن الصورة.',
                ];
            }
        }

        $res = WhatsAppChannel::sendImage(
            $conv,
            url(Storage::disk('public')->url($path)),
            mb_substr($offer->title, 0, 200),
            'bot'
        );

        if (!$res['ok']) {
            WaLog::warning('bot.card.send_failed', ['offer_id' => $offer->id, 'conv_id' => $conv->id, 'code' => $res['code']]);

            return [
                'sent' => false,
                'note' => 'تعذّر إرسال الصورة. اذكر التفاصيل نصّاً بإيجاز بدلاً منها.',
            ];
        }

        return [
            'sent' => true,
            'offer' => $offer->title,
            'note' => 'أُرسلت البطاقة للعميل بالفعل. لا تصف محتواها ولا تعيد سرد الأسعار — '
                . 'اكتفِ بجملة قصيرة مثل دعوته للاطّلاع وسؤاله أيّ فندق يناسبه.',
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
            // كان القصّ عند 8 أحرف (طول رمز مطار) يقطع أسماء المدن: «المدينة المنورة» ← «المدينة»
            'route_from' => mb_substr((string) ($in['route_from'] ?? ''), 0, 60) ?: null,
            'route_to' => mb_substr((string) ($in['route_to'] ?? ''), 0, 60) ?: null,
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

        $selection = self::bookingSelection($offer, $in);

        $qr = QuoteRequest::create([
            'request_number' => QuoteRequest::nextNumber(),
            'conversation_id' => $conv->id,
            'client_id' => $conv->client_id,
            'offer_id' => $offer->id,
            'phone' => $conv->phone,
            'customer_name' => $conv->display_name,
            'type' => 'package',
            // العرض الجاهز بتاريخه؛ وإلا التاريخ الذي طلبه العميل — والموظف يثبّته عند التقفيل
            'depart_date' => $offer->is_ready ? $offer->trip_date : self::date($in['travel_date'] ?? null),
            'return_date' => $offer->is_ready ? $offer->return_date : null,
            'pax_adults' => max(1, (int) ($in['adults'] ?? 1)),
            'pax_children' => max(0, (int) ($in['children'] ?? 0)),
            'pax_infants' => max(0, (int) ($in['infants'] ?? 0)),
            'details' => self::bookingDetails($offer, $in, $selection),
            // اختيار العميل بمراجع الحمولة نفسها — يتحوّل لفاتورة عرض مملوءة بضغطة
            'offer_selection' => $selection,
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
     * يترجم option_ref وsupplement_refs من الحمولة إلى خيارات العرض.
     * مرجع قديم أو غير صالح لا يُسقط الطلب: يبقى النص الحر والموظف يختار.
     *
     * @return array{sel: array, room: ?string, pax: int}|null
     */
    private static function bookingSelection(Offer $offer, array $in): ?array
    {
        $ref = trim((string) ($in['option_ref'] ?? ''));
        $pax = max(1, (int) ($in['adults'] ?? 1) + max(0, (int) ($in['children'] ?? 0)));
        if ($offer->isVisa()) {
            return ['sel' => [], 'room' => null, 'pax' => $pax];
        }

        $option = $ref !== '' ? $offer->sellOptions()->first(fn ($o) => $o->ref === $ref) : null;
        if (!$option) {
            return null;
        }

        $sel = $option->sel;
        foreach ((array) ($in['supplement_refs'] ?? []) as $sup) {
            foreach ($offer->groups->reject->isStay() as $g) {
                $opt = $g->activeOptions()->firstWhere('id', (int) $sup);
                if ($opt) {
                    $sel[$g->id] = $opt->id;
                }
            }
        }

        $room = array_search(trim((string) ($in['room_type'] ?? '')), \App\Models\OfferOption::ROOM_TYPES, true) ?: null;
        if ($room && (($option->prices[$room] ?? null) === null)) {
            $room = null;
        }

        return ['sel' => $sel, 'room' => $room, 'pax' => $pax];
    }

    /** سطر تفاصيل الحجز كما يراه الموظف — الفندق وسعة الغرفة إن اختارهما العميل */
    private static function bookingDetails(Offer $offer, array $in, ?array $selection = null): string
    {
        $parts = ['طلب حجز عرض: ' . $offer->title];

        if ($selection && $selection['sel']) {
            $labels = collect(\App\Services\OfferPricing::selectionLabels($offer, $selection['sel']))
                ->map(fn ($l) => "{$l['group']}: {$l['option']}")->implode(' — ');
            if ($labels !== '') {
                $parts[] = $labels;
            }
        }

        if (!empty($in['hotel'])) {
            $parts[] = 'الفندق: ' . mb_substr((string) $in['hotel'], 0, 150);
        }
        if (!empty($in['room_type'])) {
            $parts[] = 'الغرفة: ' . mb_substr((string) $in['room_type'], 0, 30);
        }
        if (!empty($in['details'])) {
            $parts[] = mb_substr((string) $in['details'], 0, 1500);
        }

        return implode(' — ', $parts);
    }

    /**
     * التحويل الكامل: إيقاف البوت + شارة تدخّل + إشعار الأدمن وموظف العميل المسؤول.
     */
    private static function escalate(WaConversation $conv, ?QuoteRequest $qr, string $title): void
    {
        // الأولوية: توكيل قائم ← مختصّ الموضوع ← موظف العميل المسؤول
        $assignee = TopicRouter::assigneeFor($conv);

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
            'note' => 'أبلغ العميل بلطف أنّ موظفاً سيتواصل معه، وإن كان الوقت خارج الدوام فمع بداية الدوام. جملة واحدة، ثم توقّف عن الردّ.',
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
