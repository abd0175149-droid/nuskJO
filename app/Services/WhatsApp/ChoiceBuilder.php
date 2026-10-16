<?php

namespace App\Services\WhatsApp;

use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\WaChoiceSet;
use App\Models\WaConversation;
use App\Services\SellOption;
use App\Services\WhatsApp\Outbound\InteractiveTypes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * خيارات تفاعلية من بيانات الخادم وحدها (E36): العناوين والأسعار من Offer::toBotSummary/toBotArray وpriceFrom
 * وsellOptions، فلا نص ولا رقم ولا معرّف من النموذج. العرض الذي يفشل forBot لا يظهر.
 *
 * كل دالة تعيد ['type' => list|button, 'kind', 'sections'|'buttons', 'button'?, 'header'?, 'set' => WaChoiceSet غير محفوظ]،
 * أو null إن لم توجد خيارات. المعرّف لكل خيار ch:{nonce}:{key}: nonce عشوائي من 16 حرفاً، وkey ‏r1…r10 أو book|ask|human.
 * المجموعة تنتهي بانتهاء النافذة (حتى 24 ساعة من آخر وارد).
 */
final class ChoiceBuilder
{
    public const BOOK = 'book';

    public const ASK = 'ask';

    public const HUMAN = 'human';

    /** قائمة العروض بالحد والترتيب نفسيهما في get_offers (الأرخص «يبدأ من» أولاً)، حتى 10 صفوف */
    public static function offersMenu(WaConversation $conv, ?string $category = null): ?array
    {
        $q = Offer::forBot()->with(['groups.options.stays', 'options.stays'])->orderBy('sort_order')->orderByDesc('id');
        if ($category !== null && $category !== '') {
            if (!isset(Offer::CATEGORIES[$category])) {
                return null;
            }
            $q->where('category', $category);
        }
        $offers = $q->limit(12)->get()->sortBy(fn ($o) => $o->priceFrom() ?? INF)->take(InteractiveTypes::MAX_ROWS)->values();

        $options = [];
        foreach ($offers as $i => $o) {
            $s = $o->toBotSummary();
            $price = $s['price_from_per_person_jod'] ?? null;
            $options[] = self::option('r'.($i + 1), (string) $s['title'], match (true) {
                $price === null => 'اسأل عن السعر',
                $o->isVisa() => self::money((float) $price).' د.أ للفرد',
                default => 'يبدأ من '.self::money((float) $price).' د.أ للفرد',
            }, ['offer_id' => (int) $s['id']]);
        }

        $header = $category ? 'عروض '.Offer::CATEGORIES[$category] : null;

        return self::list($conv, 'offers_menu', 'العروض', $options, $header);
    }

    /** التصنيفات التي فيها عرض واحد على الأقل يظهر للبوت */
    public static function categories(WaConversation $conv): ?array
    {
        $counts = Offer::forBot()->get(['id', 'category'])->countBy('category');
        $options = [];
        foreach (Offer::CATEGORIES as $key => $label) {
            $n = (int) ($counts[$key] ?? 0);
            if ($n > 0 && count($options) < InteractiveTypes::MAX_ROWS) {
                $options[] = self::option('r'.(count($options) + 1), $label, $n === 1 ? 'عرض واحد متاح' : "{$n} عروض متاحة", ['category' => $key]);
            }
        }

        return self::list($conv, 'category', 'التصنيفات', $options);
    }

    /** خيارات بيع العرض (فندق أو فندقان معاً): العنوان اسم الخيار أو أسماء فنادقه، والوصف «من … د.أ للفرد» */
    public static function offerOptions(WaConversation $conv, Offer $o): ?array
    {
        $options = [];
        foreach ($o->sellOptions()->take(InteractiveTypes::MAX_ROWS)->values() as $i => $opt) {
            $hotels = collect($opt->toBotArray(false)['hotels'] ?? [])->pluck('hotel')->filter()->implode(' + ');
            $min = $opt->minPrice();
            $options[] = self::option('r'.($i + 1), (string) ($opt->label ?: ($hotels ?: 'الخيار '.($i + 1))),
                $min !== null ? 'من '.self::money($min).' د.أ للفرد' : 'اسأل عن السعر',
                ['offer_id' => (int) $o->id, 'option_ref' => (string) $opt->ref]);
        }

        return self::list($conv, 'offer_option', 'الخيارات', $options, self::offerHeader($o));
    }

    /** سعات الغرف المتاحة لخيار واحد (حتى 4)، بسعر الفرد «غير نهائي» */
    public static function roomTypes(WaConversation $conv, Offer $o, string $optionRef): ?array
    {
        $opt = self::sellOption($o, $optionRef);
        if (!$opt) {
            return null;
        }
        $options = [];
        foreach ($opt->availablePrices() as $room => $p) {
            $options[] = self::option('r'.(count($options) + 1), (string) (OfferOption::ROOM_TYPES[$room] ?? $p['label']),
                self::money((float) $p['price_per_person_jod']).' د.أ للفرد — غير نهائي',
                ['offer_id' => (int) $o->id, 'option_ref' => (string) $opt->ref, 'room' => (string) $room]);
        }

        return self::list($conv, 'room_type', 'سعة الغرفة', $options, self::offerHeader($o));
    }

    /** «احجز هذا» و«سؤال آخر» و«كلّم موظفاً»: الخيار والغرفة يُقبلان إن كانا ما زالا متاحين، وإلا يُسقطان */
    public static function bookingActions(WaConversation $conv, Offer $o, ?string $optionRef = null, ?string $room = null): array
    {
        $ref = ['offer_id' => (int) $o->id];
        $opt = $optionRef !== null && $optionRef !== '' ? self::sellOption($o, $optionRef) : null;
        if ($opt) {
            $ref['option_ref'] = (string) $opt->ref;
            if ($room !== null && isset($opt->availablePrices()[$room])) {
                $ref['room'] = $room;
            }
        }
        $options = [
            self::option(self::BOOK, 'احجز هذا', null, $ref),
            self::option(self::ASK, 'سؤال آخر', null, $ref),
            self::option(self::HUMAN, 'كلّم موظفاً', null, $ref),
        ];
        $set = self::newSet($conv, 'booking_actions', $options);

        return [
            'type' => 'button', 'kind' => 'booking_actions', 'header' => self::offerHeader($o), 'set' => $set,
            'buttons' => array_map(fn ($op) => ['id' => self::id($set, $op['key']), 'title' => $op['title']], $options),
        ];
    }

    /** عناوين المجموعة (لنتيجة الأداة وسياق النموذج)، بلا معرّفات ولا أسعار @return list<string> */
    public static function titles(array $built): array
    {
        return array_values(array_map(fn ($o) => (string) $o['title'], (array) $built['set']->options));
    }

    public static function id(WaChoiceSet $set, string $key): string
    {
        return 'ch:'.$set->nonce.':'.$key;
    }

    /** نهاية النافذة (24 ساعة من آخر وارد)، ولا تتجاوز يوماً من الآن */
    public static function expiresAt(WaConversation $conv): Carbon
    {
        $end = $conv->last_inbound_at?->copy()->addDay();

        return $end && $end->lt(now()->addDay()) ? $end : now()->addDay();
    }

    public static function newSet(WaConversation $conv, string $kind, array $options, ?string $nonce = null): WaChoiceSet
    {
        return new WaChoiceSet([
            'conversation_id' => $conv->id, 'kind' => $kind, 'nonce' => $nonce ?? Str::random(16),
            'options' => array_values($options), 'expires_at' => self::expiresAt($conv),
        ]);
    }

    /** سعر بلا أصفار زائدة: 1,250 و450.5 */
    public static function money(float $p): string
    {
        return rtrim(rtrim(number_format($p, 3, '.', ','), '0'), '.');
    }

    public static function sellOption(Offer $o, string $ref): ?SellOption
    {
        return $o->sellOptions()->first(fn ($opt) => $opt->ref === $ref);
    }

    private static function list(WaConversation $conv, string $kind, string $button, array $options, ?string $header = null): ?array
    {
        if ($options === []) {
            return null;
        }
        $set = self::newSet($conv, $kind, $options);

        return [
            'type' => 'list', 'kind' => $kind, 'button' => $button, 'header' => $header, 'set' => $set,
            'sections' => [['rows' => array_map(fn ($op) => array_filter([
                'id' => self::id($set, $op['key']), 'title' => $op['title'], 'description' => $op['description'] ?? null,
            ], fn ($v) => $v !== null), $options)]],
        ];
    }

    private static function option(string $key, string $title, ?string $description, array $ref): array
    {
        return array_filter([
            'key' => $key,
            'title' => self::title($title),
            'description' => $description !== null ? mb_substr($description, 0, InteractiveTypes::ROW_DESCRIPTION) : null,
            'ref' => $ref,
        ], fn ($v) => $v !== null);
    }

    /** عنوان حتى 24 حرفاً: الأطول يُقص إلى 23 مع «…» (mb_substr، لا قص بايتات) */
    private static function title(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        if ($title === '') {
            return '—';
        }

        return mb_strlen($title) > InteractiveTypes::ROW_TITLE ? mb_substr($title, 0, InteractiveTypes::ROW_TITLE - 1).'…' : $title;
    }

    private static function offerHeader(Offer $o): string
    {
        return mb_substr((string) $o->title, 0, InteractiveTypes::HEADER);
    }
}
