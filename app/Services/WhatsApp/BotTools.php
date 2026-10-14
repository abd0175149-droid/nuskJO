<?php

namespace App\Services\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\QuoteRequest;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaCustomerNote;
use App\Services\NotificationService;
use App\Services\OfferCardService;
use App\Services\OfferPricing;
use App\Services\TravelersReport;
use App\Services\WhatsApp\Media\MediaArchiver;
use App\Support\FeatureFlags;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * أدوات المجال — الحدّ الفاصل بين البوت ونظام نُسك.
 *
 * قواعد ثابتة:
 *  • كل هويّة تأتي من المحادثة ($conv) لا من وسائط النموذج — فلا يرى عميل بيانات غيره. لا أداة تقبل
 *    client_id أو phone أو conversation_id (ToolRegistryContractTest).
 *  • كل رقم مالي يُحسب بالكود ويُمرَّر جاهزاً — النموذج لا يحسب.
 *  • البوت لا يكتب في دفتر الأستاذ ولا يعتمد شيئاً — أقصى ما يفعله إنشاء «طلب» وتحويل المحادثة.
 *  • نص العميل ونتائج الأدوات بيانات لا تعليمات: كل ما يكتبه النموذج يمر بالحدود هنا وفي dispatch.
 *
 * ما لا أداة له عمداً: اعتماد فاتورة أو سند، وكتابة قيد، والإرسال لرقم غير رقم المحادثة، والإرسال الجماعي،
 * وتعديل الصلاحيات أو المستخدمين، وحذف المحادثات أو الرسائل، والوصول لأسرار القناة.
 *
 * TOOLS هو المصدر الوحيد (E15): منه الإعلانات والتسميات وقائمة التحويل وحارس التسريب وقواعد التحقق ومفاتيح
 * tools_config المقبولة. لقطة tests/Snapshots/bot_declarations.json تثبّت العقد.
 */
class BotTools
{
    /**
     * tier: read | write | escalating. escalates: نجاحها يحوّل المحادثة لموظف. disableable=false: لا تُطفأ من أي مكان
     * (سياسة واتساب تشترط مسار تصعيد بشري). rules: تحقق الخادم (المخطط تلميح للنموذج لا ضمان).
     */
    public const TOOLS = [
        'get_offers' => [
            'label' => 'عرض العروض والباقات', 'handler' => 'getOffers', 'tier' => 'read', 'destructive' => false, 'idempotent' => true,
            'escalates' => false, 'disableable' => true, 'max_result_bytes' => 6000,
            'description' => 'اجلب قائمة العروض والباقات المتاحة حالياً (عمرة، حج، تذاكر، فنادق، تأشيرات...). '
                .'استدعِها عندما يسأل العميل عن العروض أو الأسعار أو ما هو متاح. اعرض النتائج بإيجاز باسم العرض و«يبدأ من» ولا تخترع عرضاً غير موجود. '
                .'انتبه: شكل العرض يختلف بتصنيفه — عروض الفنادق تأتي بخيارات لكل خيار أسعار للفرد حسب سعة الغرفة، '
                .'وعمرة «مكة والمدينة» يشمل خيارها فندقين بسعر واحد لهما معاً، والتأشيرات لها سعر واحد للفرد بلا فنادق. '
                .'التزم دائماً بحقل pricing_note المرافق لكل عرض ولا تصف التسعير بغيره.',
            'properties' => [
                'category' => ['type' => 'string', 'description' => 'تصنيف اختياري: umrah|hajj|flight|visa|hotel|transport|tour|package', 'maxLength' => 20],
            ],
            'required' => [],
            'rules' => ['category' => 'nullable|string|max:20'],
        ],
        'get_offer_details' => [
            'label' => 'تفاصيل عرض', 'handler' => 'getOfferDetails', 'tier' => 'read', 'destructive' => false, 'idempotent' => true,
            'escalates' => false, 'disableable' => true,
            'description' => 'تفاصيل عرض محدد برقمه: ما يشمله وما لا يشمله وخياراته وأسعارها، أو شروط التأشيرة ومدّة إصدارها إن كان تأشيرة. '
                .'استدعِها بعد أن يختار العميل عرضاً من القائمة. اذكر السعر دائماً بوصفه «للفرد» وأنّه غير نهائي ويحتاج تأكيد الموظف.',
            'properties' => ['offer_id' => ['type' => 'integer', 'description' => 'رقم العرض', 'minimum' => 1]],
            'required' => ['offer_id'],
            'rules' => ['offer_id' => 'required|integer|min:1'],
        ],
        'send_offer_card' => [
            'label' => 'إرسال صورة بطاقة العرض', 'handler' => 'sendOfferCard', 'tier' => 'write', 'destructive' => false, 'idempotent' => false,
            'escalates' => false, 'disableable' => true,
            'description' => 'أرسل للعميل صورة بطاقة العرض (تحوي الفنادق والأسعار حسب سعة الغرفة وما يشمله العرض). '
                .'استدعِها بعد أن يختار العميل عرضاً محدّداً أو يطلب تفاصيله أو صورته أو «التفاصيل كاملة». '
                .'أرسل البطاقة مرّة واحدة لكل عرض في المحادثة، ولا تصف محتوى الصورة بعدها — جملة قصيرة تكفي.',
            'properties' => ['offer_id' => ['type' => 'integer', 'description' => 'رقم العرض', 'minimum' => 1]],
            'required' => ['offer_id'],
            'rules' => ['offer_id' => 'required|integer|min:1'],
        ],
        'set_topic' => [
            'label' => 'تحديد موضوع المحادثة وتوجيهها', 'handler' => 'setTopic', 'tier' => 'write', 'destructive' => false, 'idempotent' => true,
            'escalates' => false, 'disableable' => true,
            'description' => 'سجّل موضوع المحادثة فور اتّضاحه — حتى قبل أي تحويل لموظف. '
                .'هذا يربط المحادثة بالموظف المختصّ فيتابعها من البداية. '
                .'استدعِها مرّة واحدة لكل موضوع، وإن تعدّدت اهتمامات العميل سجّلها كلها. '
                .'لا تُخبر العميل بذلك ولا تذكره في ردّك إطلاقاً.',
            'properties' => [
                'topics' => ['type' => 'array', 'description' => 'موضوع أو أكثر من: {topics}', 'items' => ['type' => 'string', 'maxLength' => 40], 'maxItems' => 4],
            ],
            'required' => ['topics'],
            'rules' => ['topics' => 'required|array|max:4', 'topics.*' => 'string|max:40'],
        ],
        'get_my_balance' => [
            'label' => 'رصيد ذمّة العميل', 'handler' => 'getBalance', 'tier' => 'read', 'destructive' => false, 'idempotent' => true,
            'escalates' => false, 'disableable' => true,
            'description' => 'رصيد ذمّة العميل الحالي (كم عليه من مبالغ). تعمل فقط إن كان رقم المحادثة مربوطاً بحساب عميل مسجّل.',
            'properties' => [], 'required' => [], 'rules' => [],
        ],
        'get_my_invoices' => [
            'label' => 'فواتير العميل والمتبقي', 'handler' => 'getInvoices', 'tier' => 'read', 'destructive' => false, 'idempotent' => true,
            'escalates' => false, 'disableable' => true,
            'description' => 'فواتير العميل الحالي المعتمدة مع المبلغ المتبقي على كل فاتورة. استدعِها إذا سأل عن فواتيره أو ما تبقّى عليه.',
            'properties' => [], 'required' => [], 'rules' => [],
        ],
        'get_my_trips' => [
            'label' => 'رحلات العميل القادمة', 'handler' => 'getTrips', 'tier' => 'read', 'destructive' => false, 'idempotent' => true,
            'escalates' => false, 'disableable' => true,
            'description' => 'رحلات العميل الحالي القادمة (تواريخ السفر المسجّلة على فواتيره المعتمدة).',
            'properties' => [], 'required' => [], 'rules' => [],
        ],
        'request_quote' => [
            'label' => 'طلب تسعير ← تحويل لموظف', 'handler' => 'requestQuote', 'tier' => 'escalating', 'destructive' => false, 'idempotent' => false,
            'escalates' => true, 'disableable' => true,
            'description' => 'أنشئ طلب تسعير وحوّل المحادثة لموظف. استدعِها عندما يطلب العميل سعراً نهائياً لرحلة طيران أو خدمة، أو يسأل عن توفّر المقاعد. اجمع أولاً بالحوار: المسار (من/إلى) وتاريخ السفر وعدد المسافرين. لا تذكر أي سعر من عندك أبداً — الموظف هو من يسعّر.',
            'properties' => [
                'type' => ['type' => 'string', 'description' => 'flight|package|visa|hotel|transport|other', 'enum' => ['flight', 'package', 'visa', 'hotel', 'transport', 'other']],
                'route_from' => ['type' => 'string', 'description' => 'مدينة/مطار المغادرة', 'maxLength' => 60],
                'route_to' => ['type' => 'string', 'description' => 'مدينة/مطار الوصول', 'maxLength' => 60],
                'depart_date' => ['type' => 'string', 'description' => 'تاريخ السفر YYYY-MM-DD'],
                'return_date' => ['type' => 'string', 'description' => 'تاريخ العودة إن وُجد YYYY-MM-DD'],
                'adults' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99],
                'children' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 99],
                'infants' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
                'details' => ['type' => 'string', 'description' => 'ملخّص ما طلبه العميل بكلماته', 'maxLength' => 2000],
            ],
            'required' => ['type', 'details'],
            'rules' => [
                'type' => 'required|string|in:flight,package,visa,hotel,transport,other', 'route_from' => 'nullable|string|max:60', 'route_to' => 'nullable|string|max:60',
                'depart_date' => 'nullable|date', 'return_date' => 'nullable|date', 'adults' => 'nullable|integer|min:1|max:99',
                'children' => 'nullable|integer|min:0|max:99', 'infants' => 'nullable|integer|min:0|max:20', 'details' => 'required|string|max:2000',
            ],
        ],
        'confirm_booking' => [
            'label' => 'تأكيد حجز ← تحويل لموظف', 'handler' => 'confirmBooking', 'tier' => 'escalating', 'destructive' => false, 'idempotent' => false,
            'escalates' => true, 'disableable' => true,
            'description' => 'العميل يريد تأكيد حجز عرض معيّن. استدعِها عند موافقته الصريحة على الحجز. تُنشئ طلب حجز وتحوّل المحادثة لموظف ليُتمّه. لا تؤكّد للعميل أنّ الحجز مكتمل — قل إنّ موظفاً سيتواصل لإتمامه.',
            'properties' => [
                'offer_id' => ['type' => 'integer', 'description' => 'رقم العرض المطلوب حجزه', 'minimum' => 1],
                'option_ref' => ['type' => 'string', 'description' => 'قيمة ref للخيار الذي اختاره العميل من options في get_offer_details — انسخها كما هي', 'maxLength' => 64],
                'supplement_refs' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 64], 'maxItems' => 10, 'description' => 'قيم ref للإضافات التي طلبها العميل من supplements (مثل باص VIP) — اتركها فارغة إن لم يطلب إضافة'],
                'hotel' => ['type' => 'string', 'description' => 'الخيار الذي اختاره العميل: اسم الفندق، أو الفندقين معاً في عمرة مكة والمدينة', 'maxLength' => 150],
                'room_type' => ['type' => 'string', 'description' => 'سعة الغرفة المطلوبة: مفردة|ثنائية|ثلاثية|رباعية', 'maxLength' => 30],
                'adults' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99],
                'children' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 99],
                'infants' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
                'travel_date' => ['type' => 'string', 'description' => 'تاريخ السفر المطلوب إن ذكره العميل YYYY-MM-DD'],
                'details' => ['type' => 'string', 'description' => 'أي تفاصيل ذكرها العميل (أسماء، ملاحظات، تواريخ مفضّلة)', 'maxLength' => 1500],
            ],
            'required' => ['offer_id', 'details'],
            'rules' => [
                'offer_id' => 'required|integer|min:1', 'option_ref' => 'nullable|string|max:64', 'supplement_refs' => 'nullable|array|max:10',
                'supplement_refs.*' => 'string|max:64', 'hotel' => 'nullable|string|max:150', 'room_type' => 'nullable|string|max:30',
                'adults' => 'nullable|integer|min:1|max:99', 'children' => 'nullable|integer|min:0|max:99', 'infants' => 'nullable|integer|min:0|max:20',
                'travel_date' => 'nullable|date', 'details' => 'required|string|max:1500',
            ],
        ],
        'save_note' => [
            'label' => 'حفظ ملاحظة عن العميل', 'handler' => 'saveNote', 'tier' => 'write', 'destructive' => false, 'idempotent' => false,
            'escalates' => false, 'disableable' => true,
            'description' => 'احفظ ملاحظة مهمة عن العميل لتذكّرها لاحقاً (تفضيلاته، عدد أفراد عائلته، اسمه). لا تحفظ أسراراً ولا أرقام بطاقات ولا أرقام جوازات أو هويات.',
            'properties' => ['note' => ['type' => 'string', 'description' => 'الملاحظة — 300 حرف كحدّ أقصى', 'maxLength' => 300]],
            'required' => ['note'],
            'rules' => ['note' => 'required|string|max:300'],
        ],
        'handoff_to_human' => [
            'label' => 'تحويل لموظف', 'handler' => 'handoff', 'tier' => 'escalating', 'destructive' => false, 'idempotent' => true,
            'escalates' => true, 'disableable' => false,
            'description' => 'حوّل المحادثة لموظف بشري. استدعِها إذا طلب العميل التحدث مع شخص، أو كان غاضباً أو يشكو، أو كان طلبه خارج قدراتك.',
            'properties' => ['reason' => ['type' => 'string', 'description' => 'سبب التحويل بإيجاز', 'maxLength' => 300]],
            'required' => ['reason'],
            'rules' => ['reason' => 'required|string|max:300'],
        ],
    ];

    /** سقف نتيجة أداة البوت ما لم تحدّد الأداة سقفها (E15.7) */
    public const DEFAULT_MAX_RESULT_BYTES = 6000;

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::TOOLS);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return array_map(fn ($t) => $t['label'], self::TOOLS);
    }

    /** الأدوات التي ينجح بها التحويل لموظف (HandoffGuard) @return list<string> */
    public static function escalating(): array
    {
        return array_keys(array_filter(self::TOOLS, fn ($t) => $t['escalates']));
    }

    public static function disableable(string $name): bool
    {
        return (bool) (self::TOOLS[$name]['disableable'] ?? true);
    }

    /** مفعّلة لهذا الإعداد: ما لا يُطفأ مفعّل دائماً مهما كان في tools_config */
    public static function isEnabled(WaBotSetting $s, string $name): bool
    {
        return isset(self::TOOLS[$name]) && (!self::disableable($name) || $s->toolEnabled($name));
    }

    /** للإعدادات: [{key, label, escalates, disableable}] */
    public static function meta(): array
    {
        return array_map(fn ($name) => ['key' => $name, 'label' => self::TOOLS[$name]['label'],
            'escalates' => self::TOOLS[$name]['escalates'], 'disableable' => self::TOOLS[$name]['disableable']], self::names());
    }

    /** @return array<string, string> */
    public static function rules(string $name): array
    {
        return self::TOOLS[$name]['rules'] ?? [];
    }

    public static function maxResultBytes(string $name): int
    {
        return (int) (self::TOOLS[$name]['max_result_bytes'] ?? self::DEFAULT_MAX_RESULT_BYTES);
    }

    /** إعلانات الأدوات المفعّلة بصيغة Anthropic tool_use (يحوّلها كل مزوّد لصيغته) */
    public static function declarations(WaBotSetting $s, WaConversation $conv): array
    {
        $out = [];
        foreach (self::TOOLS as $name => $t) {
            if (!self::isEnabled($s, $name)) {
                continue;
            }
            $props = $t['properties'];
            if (isset($props['topics']['description'])) {
                $props['topics']['description'] = str_replace('{topics}', implode(' | ', array_keys(TopicRouter::TOPICS)), $props['topics']['description']);
            }
            $schema = ['type' => 'object', 'properties' => $props];
            if ($t['required']) {
                $schema['required'] = $t['required'];
            }
            $out[] = ['name' => $name, 'description' => $t['description'], 'input_schema' => $schema];
        }

        return $out;
    }

    // ==================== التنفيذ ====================

    /**
     * التنفيذ المباشر بالمعالج المسجّل: للكود الموثوق والاختبارات. ما يطلبه النموذج يمر بـ ToolInvoker (E15.3)
     * الذي يفحص الاسم المعلن والإذن والمدخلات قبل هذا.
     */
    public static function execute(string $name, array $in, WaConversation $conv): array
    {
        $handler = self::TOOLS[$name]['handler'] ?? null;
        if ($handler === null) {
            return ['error' => 'أداة غير معروفة', 'note' => 'اعتذر للعميل بإيجاز.'];
        }

        return self::{$handler}($in, $conv);
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
            ->map(fn ($o) => $o->toBotSummary())->values()->all();

        return [
            'count' => count($offers),
            'offers' => $offers,
            'note' => $offers
                ? 'اعرض العروض بإيجاز (الاسم والسعر والمدة، وتاريخ الانطلاق إن وُجد trip_date). لا تخترع تفاصيل غير مذكورة. '
                    .'الفنادق والخيارات وأسعارها في get_offer_details للعرض الذي يختاره العميل. '
                    .'availability تقريبية: قلها كما هي («متوفر» أو «مقاعد محدودة») ولا تذكر عدد مقاعد ولا تؤكّد مقعداً. اسأل العميل أيّ عرض يهمّه.'
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
                .'لا تذكر عدد مقاعد ولا تؤكّد توفّراً غير ما في availability.',
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

    private const CARD_AS_TEXT = 'تعذّر إرسال الصورة. اذكر تفاصيل العرض وأسعاره نصّاً بإيجاز بدلاً منها، ولا تعتذر عن الصورة.';

    /** إرسال بطاقة العرض كصورة — تُولَّد عند الطلب إن لم تكن جاهزة */
    private static function sendOfferCard(array $in, WaConversation $conv): array
    {
        $offer = Offer::forBot()->with(['groups.options.stays', 'options.stays'])->find($in['offer_id'] ?? 0);
        if (!$offer) {
            return ['error' => 'العرض غير متاح', 'note' => 'أخبر العميل أنّ العرض غير متاح واعرض البدائل.'];
        }

        $path = $offer->cardImage();

        // البطاقة غير موجودة أو قديمة بعد تعديل الأسعار → نولّدها الآن، إن بقي وقت يكفي Chromium
        if (!$path || $offer->cardIsStale()) {
            $run = ReplyRun::current();
            if ($run && !$run->hasAtLeast(ReplyRun::CARD_MIN_SECONDS)) {
                WaLog::warning('bot.card.skipped_budget', ['offer_id' => $offer->id, 'conv_id' => $conv->id]);

                return [
                    'sent' => false,
                    'note' => self::CARD_AS_TEXT,
                ];
            }
            try {
                $path = OfferCardService::generate($offer);
            } catch (\Throwable $e) {
                WaLog::error('bot.card.render_failed', ['offer_id' => $offer->id, 'conv_id' => $conv->id, 'exception' => $e]);

                return [
                    'sent' => false,
                    'note' => self::CARD_AS_TEXT,
                ];
            }
        }

        // موظف تسلّم المحادثة أثناء التوليد؟ لا صورة، وتنقطع الحلقة
        if (($run = ReplyRun::current()) && ($drop = $run->mayDeliver($conv))) {
            $run->abort = $drop;

            return ['sent' => false, 'note' => 'لا ترسل شيئاً آخر.'];
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

        // دليل السعر الذي رآه العميل (E14.11): البطاقة تُستبدل عند إعادة التوليد، فتُنسخ للأرشيف لحظة الإرسال
        if (FeatureFlags::on('media_archive') && !empty($res['message_id'])) {
            app(MediaArchiver::class)->storeOutbound((string) $res['message_id'], Storage::disk('public')->path($path), 'image/png');
        }

        return [
            'sent' => true,
            'offer' => $offer->title,
            'note' => 'أُرسلت البطاقة للعميل بالفعل. لا تصف محتواها ولا تعيد سرد الأسعار — '
                .'اكتفِ بجملة قصيرة مثل دعوته للاطّلاع وسؤاله أيّ فندق يناسبه.',
        ];
    }

    private static function getBalance(array $in, WaConversation $conv): array
    {
        if (!$conv->financialClientId()) {
            return self::notLinked();
        }
        // غياب العميل أو حسابه «غير متاح» صريحاً، لا رصيد صفري يُقرأ «مسدّدة» (E12.7)
        $client = $conv->client;
        if (!$client) {
            return ['ok' => false, 'error' => 'تعذّر جلب الحساب', 'note' => self::UNAVAILABLE_NOTE];
        }
        if (!$client->account) {
            return ['ok' => false, 'available' => false,
                'note' => 'الرصيد غير متاح الآن. لا تذكر أي رقم ولا تقل إن ذمّته مسدّدة. اعتذر واعرض تحويله لموظف.'];
        }
        $balance = round((float) $client->account->balance, 3);

        return [
            'ok' => true,
            'client' => $client->name,
            'balance_jod' => $balance,
            'note' => match (true) {
                $balance > 0 => 'أبلغ العميل بالمبلغ المتبقي عليه كما هو — لا تحسب ولا تقرّب.',
                $balance < 0 => 'له رصيد دائن لدينا بهذا المبلغ (القيمة بالسالب). اذكره كما هو، ولا تقل إن عليه مبلغاً.',
                default => 'أبلغه أنّ ذمّته مسدّدة ولا يوجد مبلغ متبقٍ.',
            },
        ];
    }

    private static function getInvoices(array $in, WaConversation $conv): array
    {
        if (!$conv->financialClientId()) {
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
            // غير محسوب = غير متاح، لا «الإجمالي كله متبقٍ»
            'remaining_jod' => $remaining[$i->id] ?? null,
        ])->all();

        return [
            'count' => count($rows),
            'invoices' => $rows,
            'note' => 'اذكر الأرقام كما وردت حرفياً. المتبقي محسوب بالنظام — لا تعد حسابه. remaining_jod=null يعني «غير متاح الآن»: لا تذكر له رقماً.',
        ];
    }

    private static function getTrips(array $in, WaConversation $conv): array
    {
        if (!$conv->financialClientId()) {
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
            // عميل بلا رقم: نص فارغ (العمود NOT NULL)، والهوية من conversation_id
            'phone' => $conv->phone ?? '',
            'customer_name' => self::customerName($conv),
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

        self::auditQuote($qr, $conv);
        self::escalate($conv, $qr, '💰 طلب تسعير جديد');

        return [
            'request_number' => $qr->request_number,
            'note' => 'أبلغ العميل أنّ طلبه وصل لفريقنا وسيعود إليه موظف بالسعر النهائي وتأكيد التوفّر قريباً. '
                .'لا تذكر أي سعر ولا تؤكّد توفّر مقعد. جملة واحدة قصيرة تكفي.'.self::askForPhone($conv),
        ];
    }

    private const UNAVAILABLE_NOTE = 'المعلومة غير متاحة الآن. لا تستنتج قيمة ولا تقل لا يوجد أو مسدّد. اعتذر واعرض تحويله لموظف.';

    /** اسم الطلب: العميل المربوط، ثم الاسم المحفوظ، ثم لقب واتساب (غير موثّق) */
    private static function customerName(WaConversation $conv): ?string
    {
        return $conv->client?->name ?: ($conv->display_name ?: $conv->wa_profile_name);
    }

    /**
     * محادثة بلا رقم (E11.7): زر «مشاركة الرقم» يصل للعميل مع الطلب إن سمح وضع الهوية، فيعرف الموظف رقمه.
     * يعيد سطراً يُضاف لملاحظة النموذج، أو '' إن لم يُرسل.
     */
    private static function askForPhone(WaConversation $conv): string
    {
        if ($conv->hasPhone() || !IdentityMode::botServesWithoutPhone()) {
            return '';
        }
        $sent = WhatsAppChannel::sendContactRequest($conv, 'ليتابع معك موظفنا بالسعر والتفاصيل، شارك رقم هاتفك بالضغط على الزر أدناه.', 'system');

        return $sent['ok'] ? ' وصل العميلَ الآن زرٌّ لمشاركة رقم هاتفه: اطلب منه الضغط عليه، ولا تطلب منه كتابة الرقم.' : '';
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
            // عميل بلا رقم: نص فارغ (العمود NOT NULL)، والهوية من conversation_id
            'phone' => $conv->phone ?? '',
            'customer_name' => self::customerName($conv),
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

        self::auditQuote($qr, $conv);
        self::escalate($conv, $qr, '🧾 طلب تأكيد حجز');

        return [
            'request_number' => $qr->request_number,
            'offer' => $offer->title,
            'note' => 'أبلغ العميل أنّ طلب الحجز وصل وأنّ موظفاً سيتواصل معه لإتمامه وتأكيد التوفّر والدفع. '
                .'لا تقل إنّ الحجز تمّ أو تأكّد. جملة قصيرة فقط.'.self::askForPhone($conv),
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

        $room = array_search(trim((string) ($in['room_type'] ?? '')), OfferOption::ROOM_TYPES, true) ?: null;
        if ($room && (($option->prices[$room] ?? null) === null)) {
            $room = null;
        }

        return ['sel' => $sel, 'room' => $room, 'pax' => $pax];
    }

    /** سطر تفاصيل الحجز كما يراه الموظف — الفندق وسعة الغرفة إن اختارهما العميل */
    private static function bookingDetails(Offer $offer, array $in, ?array $selection = null): string
    {
        $parts = ['طلب حجز عرض: '.$offer->title];

        if ($selection && $selection['sel']) {
            $labels = collect(OfferPricing::selectionLabels($offer, $selection['sel']))
                ->map(fn ($l) => "{$l['group']}: {$l['option']}")->implode(' — ');
            if ($labels !== '') {
                $parts[] = $labels;
            }
        }

        if (!empty($in['hotel'])) {
            $parts[] = 'الفندق: '.mb_substr((string) $in['hotel'], 0, 150);
        }
        if (!empty($in['room_type'])) {
            $parts[] = 'الغرفة: '.mb_substr((string) $in['room_type'], 0, 30);
        }
        if (!empty($in['details'])) {
            $parts[] = mb_substr((string) $in['details'], 0, 1500);
        }

        return implode(' — ', $parts);
    }

    /** النوع والعرض والمحادثة فقط: اسم العميل ورقمه وتفاصيل طلبه في الطلب نفسه لا في السجل */
    private static function auditQuote(QuoteRequest $qr, WaConversation $conv): void
    {
        AuditLog::safe(AuditAction::QuoteRequestCreate, 'quote_request', $qr->id, $qr->request_number, [
            'type' => $qr->type, 'offer_id' => $qr->offer_id, 'conversation_id' => $conv->id,
        ]);
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
        ] + (ReplyState::available() ? ['attention_reason' => 'handoff'] : []));

        // التحويل من التشغيل نفسه: رسالة تأكيده لا تُسقط بحجة أن البوت صار مطفأً
        if ($run = ReplyRun::current()) {
            $run->escalated = true;
        }

        if ($qr && $assignee) {
            $qr->update(['assigned_to' => $assignee]);
        }

        AuditLog::safe(AuditAction::WaHandoff, 'wa_conversation', $conv->id, null, [
            'title' => $title, 'quote_request_id' => $qr?->id, 'assigned_to' => $assignee,
        ]);

        $who = $conv->label();
        $body = $qr
            ? "{$who} — طلب {$qr->request_number}: ".mb_substr((string) $qr->details, 0, 140)
            : "{$who} — يحتاج تدخّلاً بشرياً.";
        $url = $qr ? '/quote-requests' : '/whatsapp/inbox';

        WhatsAppInbox::notifyAdmins($title, $body, $url);

        if ($assignee) {
            try {
                NotificationService::send($assignee, $title, $body, [
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

    /**
     * تحويل يقرّره الكود (طلب صريح لموظف، حارس الوعد، الميزانية، حد المعدّل…): مدخل موثوق لا يراه النموذج ولا يمر
     * بالمنفّذ، ويعمل ولو كانت الأداة مخفية.
     */
    public static function handoffFromCode(WaConversation $conv, string $reason): array
    {
        return self::handoff(['reason' => $reason], $conv);
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
                .'اعتذر بلطف واقترح أن يتواصل معه موظف للتحقق، أو استدعِ handoff_to_human.',
        ];
    }

    private static function date(?string $d): ?string
    {
        if (!$d) {
            return null;
        }
        try {
            return Carbon::parse($d)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
