<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Offer;
use App\Models\OfferGroup;
use App\Models\OfferOption;
use App\Models\OfferStay;
use App\Models\Service;

/**
 * المصدر الوحيد لحساب فاتورة العرض: البيع من العرض، والتكلفة من الفاتورة.
 *
 * العرض يحمل بيانات البيع وحدها: السعر الأساسي، وسعر كل سعة، وفروق الخيارات.
 * الوكيل والتكلفة يتغيران كل يوم، فيُدخلان في كل فاتورة بقيمتهما الفعلية:
 *  - بنود مقترحة من اختيار العميل: كل فندق بسعته ولياليه، وكل خيار له فرق سعر.
 *  - بنود يدوية: تأشيرة، طيران، زيارات… ولها سعر بيع اختياري إن كانت خارج العرض.
 * الفندق: تكلفة الفرد لليلة × الليالي × المسافرين. غيره: تكلفة الوحدة × العدد.
 * كل تكلفة بعملتها، وتُحوَّل للدينار بسعر واحد من config/accounting.php.
 * الفاتورة تُحفظ وتحجز مقاعدها والتكاليف ناقصة، ولا تُعتمد قبل أن يكتمل كل بند.
 */
class OfferPricing
{
    public const ROOMS = ['single', 'double', 'triple', 'quad'];

    /** خدمة الإيراد الافتراضية لكل نوع بند حين لا يحدّدها البائع */
    private const SERVICE_KEYWORDS = [
        'visa' => ['تاشير', 'تأشير'],
        'flight' => ['طيران', 'تذاكر', 'تذكر'],
        'transport' => ['نقل', 'مواصلات'],
        'hotel' => ['فند'],
    ];

    private static array $serviceCache = [];

    public static function rate(): float
    {
        return (float) config('accounting.sar_to_jod', 0.19);
    }

    public static function toJod(float $amount, ?string $currency): float
    {
        return strtoupper((string) $currency) === 'SAR' ? round($amount * self::rate(), 3) : round($amount, 3);
    }

    private static function load(Offer $o): void
    {
        $o->loadMissing(['groups.options.stays']);
    }

    // ==================== اكتمال بيانات البيع ====================

    /** ما يمنع بيع العرض بفاتورة عرض — بيانات البيع وحدها. مصفوفة فارغة = يُباع */
    public static function issues(Offer $o): array
    {
        self::load($o);
        $out = [];

        if ($o->is_ready && !((int) $o->total_seats >= 1)) {
            $out[] = 'عدد المقاعد مطلوب للعرض الجاهز.';
        }

        if ($o->isVisa()) {
            if (!($o->basePrice() > 0)) $out[] = 'سعر التأشيرة للفرد مطلوب.';

            return $out;
        }

        if ($o->groups->filter->isStay()->isEmpty()) {
            $out[] = 'أضف مجموعة فنادق واحدة على الأقل.';
        }

        foreach ($o->groups as $g) {
            $active = $g->activeOptions();
            if ($active->isEmpty()) {
                $out[] = "«{$g->label}» بلا خيار فعّال.";
            }
            if ($g->isStay() && $o->pricing_mode === 'city' && !((int) $g->nights > 0)) {
                $out[] = "«{$g->label}»: عدد الليالي مطلوب.";
            }
            if (!$g->isStay()) {
                continue;
            }
            foreach ($active as $opt) {
                $lab = self::optionLabel($o, $g, $opt);
                if (!array_filter(self::ROOMS, fn ($r) => $opt->sellFor($r) !== null)) $out[] = "«{$lab}»: لا سعر بيع لأي سعة.";
                if ($opt->stays->isEmpty()) $out[] = "«{$lab}»: أضف فندقاً.";
                foreach ($opt->stays as $s) {
                    if (!(self::stayNights($o, $g, $s) > 0)) $out[] = '«' . ($s->name ?: 'فندق') . '»: عدد الليالي مطلوب.';
                }
            }
        }

        return array_values(array_unique($out));
    }

    // ==================== سعر سطر حجز ====================

    public static function stayNights(Offer $o, OfferGroup $g, OfferStay $s): int
    {
        return (int) ($o->pricing_mode === 'city' ? ($g->nights ?: $s->nights) : ($s->nights ?: $g->nights));
    }

    public static function stayCity(Offer $o, OfferGroup $g, OfferStay $s): ?string
    {
        return $o->pricing_mode === 'city' ? ($g->city ?: $s->city) : $s->city;
    }

    public static function optionLabel(Offer $o, OfferGroup $g, OfferOption $opt): string
    {
        if (!$g->isStay()) {
            return $opt->label ?: '—';
        }
        $names = $opt->stays->map(fn ($s) => ($o->pricing_mode === 'bundle' && $s->city ? $s->city . ': ' : '')
            . ($s->name ?: 'فندق') . ($s->rating ? " {$s->rating}★" : ''))->implode(' + ');

        return ($o->pricing_mode === 'bundle' && $opt->label ? $opt->label . ' — ' : '') . ($names ?: '—');
    }

    /** نوع بند التكلفة لخيار غير فندقي حسب نوع مجموعته */
    public static function kindFor(OfferGroup $g): string
    {
        return in_array($g->kind, ['transport', 'flight'], true) ? $g->kind : 'service';
    }

    /** خدمة الإيراد لبند — المحدّدة على البند، وإلا بالكلمات المفتاحية */
    public static function serviceFor(?int $explicit, string $kind, ?string $city = null): ?int
    {
        if ($explicit) {
            return $explicit;
        }
        $key = $kind . '|' . $city;
        if (array_key_exists($key, self::$serviceCache)) {
            return self::$serviceCache[$key];
        }

        $services = Service::where('is_active', true)->get(['id', 'name']);
        $norm = fn ($s) => str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], mb_strtolower((string) $s));
        $find = function (array $words, ?string $also = null) use ($services, $norm) {
            return $services->first(function ($s) use ($words, $also, $norm) {
                $n = $norm($s->name);
                foreach ($words as $w) {
                    if (mb_strpos($n, $norm($w)) !== false && (!$also || mb_strpos($n, $norm($also)) !== false)) {
                        return true;
                    }
                }

                return false;
            });
        };

        $hit = null;
        if ($kind === 'hotel' && $city) {
            $hit = $find(self::SERVICE_KEYWORDS['hotel'], mb_substr($city, 0, 4));
        }
        $hit ??= isset(self::SERVICE_KEYWORDS[$kind]) ? $find(self::SERVICE_KEYWORDS[$kind]) : null;

        return self::$serviceCache[$key] = $hit?->id;
    }

    /**
     * سعر الفرد لاختيار واحد وسعة واحدة — من العرض وحده.
     *
     * @param array $sel [group_id => option_id]
     * @return array{errors: array, parts: array, sell_pp: float}
     */
    public static function priceLine(Offer $o, array $sel, string $room): array
    {
        self::load($o);
        $errors = $parts = [];

        if ($o->basePrice() > 0) {
            $parts[] = ['label' => $o->isVisa() ? 'سعر التأشيرة' : 'السعر الأساسي', 'pp' => round($o->basePrice(), 3)];
        }

        foreach ($o->groups as $g) {
            $opt = $g->options->firstWhere('id', (int) ($sel[$g->id] ?? 0));
            if (!$opt) {
                $errors[] = "اختر «{$g->label}».";
                continue;
            }
            $lab = self::optionLabel($o, $g, $opt);
            if (!$opt->is_active) {
                $errors[] = "«{$lab}» معطّل.";
            }

            if ($g->isStay()) {
                $sp = $opt->sellFor($room);
                if ($sp === null) {
                    $errors[] = 'السعة ' . (OfferOption::ROOM_TYPES[$room] ?? $room) . " غير متاحة في «{$lab}».";
                    continue;
                }
                $parts[] = ['label' => "{$g->label}: {$lab}", 'pp' => $sp];
            } elseif ((float) $opt->sell_flat) {
                $parts[] = ['label' => "{$g->label}: {$opt->label}", 'pp' => round((float) $opt->sell_flat, 3)];
            }
        }

        return [
            'errors' => array_values(array_unique($errors)),
            'parts' => $parts,
            'sell_pp' => round(array_sum(array_column($parts, 'pp')), 3),
        ];
    }

    /** السعات المتاحة لاختيار: لها سعر بيع في كل مجموعة فنادق */
    public static function roomsFor(Offer $o, array $sel): array
    {
        self::load($o);
        $stayGroups = $o->groups->filter->isStay();

        return array_values(array_filter(self::ROOMS, function ($r) use ($stayGroups, $sel) {
            foreach ($stayGroups as $g) {
                $opt = $g->options->firstWhere('id', (int) ($sel[$g->id] ?? 0));
                if (!$opt || $opt->sellFor($r) === null) {
                    return false;
                }
            }

            return true;
        }));
    }

    // ==================== بنود التكلفة المقترحة ====================

    /**
     * بنود التكلفة التي يقترحها اختيار سطر: أسماء وكميات فقط، بلا وكيل ولا سعر.
     * الفندق بنده إلزامي (لا يُحذف) لأن كل فندق مبيع له تكلفة.
     */
    public static function template(Offer $o, array $sel, ?string $room, int $pax, int $line): array
    {
        self::load($o);
        $rows = [];
        $base = ['line' => $line, 'qty' => $pax, 'fixed' => true];

        if ($o->isVisa()) {
            return [$base + [
                'src' => 'visa', 'kind' => 'visa', 'description' => $o->title, 'nights' => null,
                'source_type' => 'visa', 'source_id' => null,
                'default_service' => self::serviceFor(null, 'visa'), 'default_currency' => 'JOD',
            ]];
        }

        foreach ($o->groups as $g) {
            $opt = $g->options->firstWhere('id', (int) ($sel[$g->id] ?? 0));
            if (!$opt) {
                continue;
            }
            if ($g->isStay()) {
                if (!$room) {
                    continue;
                }
                foreach ($opt->stays as $s) {
                    $nights = self::stayNights($o, $g, $s);
                    $city = self::stayCity($o, $g, $s);
                    $rows[] = $base + [
                        'src' => "stay:{$s->id}:{$room}", 'kind' => 'hotel',
                        'description' => ($s->name ?: 'فندق') . ($city ? " ({$city})" : '') . ' · ' . (OfferOption::ROOM_TYPES[$room] ?? $room)
                            . " · {$nights} " . ($nights >= 3 && $nights <= 10 ? 'ليالٍ' : 'ليلة'),
                        'nights' => $nights, 'source_type' => 'stay', 'source_id' => $s->id,
                        'default_service' => self::serviceFor(null, 'hotel', $city), 'default_currency' => 'SAR',
                    ];
                }
            } elseif ((float) $opt->sell_flat > 0) {
                $kind = self::kindFor($g);
                $rows[] = $base + [
                    'src' => "opt:{$opt->id}", 'kind' => $kind, 'description' => $opt->label ?: $g->label, 'nights' => null,
                    'source_type' => 'option', 'source_id' => $opt->id,
                    'default_service' => self::serviceFor(null, $kind), 'default_currency' => 'JOD',
                ];
            }
        }

        return $rows;
    }

    /** بند تكلفة كامل من قالبه وما أدخله البائع */
    private static function costRow(array $t, array $c): array
    {
        $cur = in_array($c['currency'] ?? null, ['SAR', 'JOD'], true) ? $c['currency'] : $t['default_currency'];
        $raw = $c['unit'] ?? null;
        $unit = ($raw === null || $raw === '') ? null : round(max(0, (float) $raw), 3);
        // البند المقترح عدده مسافرو سطره؛ اليدوي يحدّده البائع
        $qty = $t['fixed'] ? (int) $t['qty'] : max(0, (int) ($c['qty'] ?? $t['qty']));
        $per = round((float) $unit * ($t['nights'] ?: 1), 3);
        $perJod = self::toJod($per, $cur);
        $agent = !empty($c['agent_id']) ? (int) $c['agent_id'] : null;
        $manual = $t['src'] === 'manual';

        return [
            'src' => $t['src'],
            'line' => $t['line'],
            'fixed' => $t['fixed'],
            'kind' => $t['kind'],
            'description' => $t['description'],
            'nights' => $t['nights'],
            'qty' => $qty,
            'agent_id' => $agent,
            'service_id' => !empty($c['service_id']) ? (int) $c['service_id'] : $t['default_service'],
            'currency' => $cur,
            'unit' => $unit,
            'unit_original' => $per,
            'unit_jod' => $perJod,
            'total_original' => round($per * $qty, 3),
            'total_jod' => round($perJod * $qty, 3),
            'sell' => $manual ? round(max(0, (float) ($c['sell'] ?? 0)), 3) : 0.0,
            'source_type' => $t['source_type'],
            'source_id' => $t['source_id'],
            'done' => $agent && $per > 0 && $qty >= 1,
        ];
    }

    // ==================== الفاتورة ====================

    public static function lineKey(array $sel, string $room): string
    {
        ksort($sel);

        return json_encode(array_map('intval', $sel)) . '|' . $room;
    }

    /**
     * يبني فاتورة عرض: السطور (لقطة البيع)، وبنود التكلفة كما أدخلها البائع، والإجماليات.
     *
     * @param array $lines [{sel: {group_id: option_id}, room, pax}]
     * @param array $costs [{src, line, description, agent_id, service_id, qty, unit, currency, sell}]
     *                     src: «stay:…»/«opt:…»/«visa» لبند مقترح، و«manual» لبند يدوي
     * @param array $previous سطور اللقطة السابقة — يُحفظ سعر بيعها ما لم يتغير الاختيار
     */
    public static function build(Offer $o, array $lines, array $costs = [], float $discount = 0, array $previous = [], bool $refresh = false): array
    {
        self::load($o);
        $errors = [];
        $prevByKey = collect($previous)->keyBy('key');
        $stay = $o->groups->filter->isStay()->isNotEmpty();

        $built = [];
        $template = [];
        foreach (array_values($lines) as $i => $l) {
            $n = $i + 1;
            $sel = array_map('intval', (array) ($l['sel'] ?? []));
            $room = $stay ? (string) ($l['room'] ?? '') : 'single';
            $pax = (int) ($l['pax'] ?? 0);
            if ($pax < 1) {
                $errors[] = "السطر {$n}: عدد المسافرين 1 أو أكثر.";
            }
            if ($stay && !in_array($room, self::ROOMS, true)) {
                $errors[] = "السطر {$n}: اختر سعة الغرفة.";
                continue;
            }

            $key = self::lineKey($sel, $room);
            $prev = $prevByKey->get($key);
            $pr = $prev && !$refresh
                ? ['errors' => [], 'parts' => $prev['parts'], 'sell_pp' => $prev['sell_pp']]
                : self::priceLine($o, $sel, $room);
            foreach ($pr['errors'] as $e) {
                $errors[] = "السطر {$n}: {$e}";
            }

            $built[] = [
                'key' => $key,
                'sel' => $sel,
                'room' => $stay ? $room : null,
                'pax' => max(0, $pax),
                'labels' => self::selectionLabels($o, $sel),
                'parts' => $pr['parts'],
                'sell_pp' => $pr['sell_pp'],
                'kept' => (bool) ($prev && !$refresh),
            ];
            array_push($template, ...self::template($o, $sel, $stay ? $room : null, max(0, $pax), $i));
        }

        // البنود المقترحة من الخادم دائماً — ما أرسله البائع يملأ وكيلها وتكلفتها فقط
        $given = [];
        $manual = [];
        foreach (array_values($costs) as $c) {
            $src = (string) ($c['src'] ?? 'manual');
            if ($src === 'manual') {
                $manual[] = $c;
            } else {
                $given[(int) ($c['line'] ?? 0) . '|' . $src] = $c;
            }
        }
        $rows = [];
        foreach ($template as $t) {
            $rows[] = self::costRow($t, $given[$t['line'] . '|' . $t['src']] ?? []);
        }
        foreach ($manual as $mi => $c) {
            $n = $mi + 1;
            $desc = trim((string) ($c['description'] ?? ''));
            if ($desc === '') $errors[] = "البند اليدوي {$n}: البيان مطلوب.";
            if ((int) ($c['qty'] ?? 0) < 1) $errors[] = "البند اليدوي {$n}: العدد 1 أو أكثر.";
            $rows[] = self::costRow([
                'src' => 'manual', 'line' => null, 'fixed' => false, 'kind' => 'service', 'description' => $desc,
                'nights' => null, 'qty' => (int) ($c['qty'] ?? 0), 'source_type' => 'manual', 'source_id' => null,
                'default_service' => null, 'default_currency' => 'JOD',
            ], $c);
        }

        $items = array_map(fn ($r) => [
            'agent_id' => $r['agent_id'],
            'service_id' => $r['service_id'],
            'description' => $r['description'] ?: 'بند',
            'statement' => $r['sell'] > 0 ? 'خارج العرض' : $o->title,
            'quantity' => $r['qty'],
            'unit_price_jod' => $r['unit_jod'],
            'total_cost_jod' => $r['total_jod'],
            'source_type' => $r['source_type'],
            'source_id' => $r['source_id'],
            'cost_currency' => $r['currency'],
            'cost_original' => $r['total_original'],
            'fx_rate' => $r['currency'] === 'SAR' ? self::rate() : 1,
        ], $rows);

        $lineSell = array_sum(array_map(fn ($l) => round($l['sell_pp'] * $l['pax'], 3), $built));
        $extraSell = array_sum(array_map(fn ($r) => round($r['sell'] * $r['qty'], 3), $rows));
        $gross = round($lineSell + $extraSell, 3);
        $discount = round(max(0, $discount), 3);
        if ($discount > $gross) {
            $errors[] = 'الخصم أكبر من إجمالي البيع.';
        }
        $sell = round($gross - $discount, 3);
        $cost = round(array_sum(array_column($rows, 'total_jod')), 3);
        $missing = count(array_filter($rows, fn ($r) => !$r['done'])) + ($rows ? 0 : 1);

        return [
            'errors' => array_values(array_unique($errors)),
            'lines' => $built,
            'costs' => $rows,
            // ما بيع خارج العرض — تطبعه الفاتورة سطراً مستقلاً
            'extras' => array_values(array_map(fn ($r) => ['description' => $r['description'], 'qty' => $r['qty'], 'sell' => $r['sell']],
                array_filter($rows, fn ($r) => $r['sell'] > 0))),
            'items' => $items,
            'gross' => $gross,
            'discount' => $discount,
            'sell' => $sell,
            'cost' => $cost,
            'profit' => round($sell - $cost, 3),
            'missing' => $missing,
            'pax' => array_sum(array_column($built, 'pax')),
        ];
    }

    /** بنود فاتورة عرض ناقصة: بلا وكيل أو بلا تكلفة — من بنودها المحفوظة التي يقرؤها القيد */
    public static function missingCosts(Invoice $invoice): int
    {
        $items = $invoice->items()->get(['agent_id', 'total_cost_jod', 'quantity']);
        if ($items->isEmpty()) {
            return 1;
        }

        return $items->filter(fn ($i) => !$i->agent_id || (float) $i->total_cost_jod <= 0 || (int) $i->quantity < 1)->count();
    }

    /**
     * «آخر تكلفة»: ما استُخدم لكل بند مقترح في آخر فاتورة معتمدة على العرض، والبنود اليدوية لآخر فاتورة فيها بنود يدوية.
     * اقتراح من السجل يملأ الحقول ويبقى قابلاً للتعديل — لا قيمة مخزّنة على العرض.
     */
    public static function lastCosts(Offer $o, ?int $ignoreInvoiceId = null): array
    {
        $bySrc = [];
        $byStay = [];
        $manual = null;
        $invoices = Invoice::where('offer_id', $o->id)->where('status', 'approved')
            ->when($ignoreInvoiceId, fn ($q) => $q->where('id', '!=', $ignoreInvoiceId))
            ->orderByDesc('approved_at')->orderByDesc('id')->limit(30)
            ->get(['id', 'invoice_number', 'offer_lines', 'offer_seats']);

        foreach ($invoices as $inv) {
            $rows = $inv->offer_lines['costs'] ?? [];
            foreach ($rows as $r) {
                $src = $r['src'] ?? 'manual';
                if ($src === 'manual' || empty($r['done'])) {
                    continue;
                }
                $bySrc[$src] ??= [
                    'agent_id' => $r['agent_id'], 'unit' => $r['unit'], 'currency' => $r['currency'],
                    'service_id' => $r['service_id'], 'number' => $inv->invoice_number,
                ];
                // وكيل الفندق نفسه بأي سعة — يُقترح حين لا تكلفة سابقة لهذه السعة
                if (str_starts_with($src, 'stay:')) {
                    $byStay[implode(':', array_slice(explode(':', $src), 0, 2))] ??= [
                        'agent_id' => $r['agent_id'], 'currency' => $r['currency'], 'service_id' => $r['service_id'], 'number' => $inv->invoice_number,
                    ];
                }
            }
            if (!$manual) {
                $m = array_values(array_filter($rows, fn ($r) => ($r['src'] ?? '') === 'manual'));
                if ($m) {
                    $manual = [
                        'number' => $inv->invoice_number,
                        'pax' => (int) ($inv->offer_lines['pax'] ?? $inv->offer_seats),
                        'rows' => array_map(fn ($r) => [
                            'description' => $r['description'], 'agent_id' => $r['agent_id'], 'service_id' => $r['service_id'],
                            'qty' => $r['qty'], 'unit' => $r['unit'], 'currency' => $r['currency'], 'sell' => $r['sell'] ?? 0,
                        ], $m),
                    ];
                }
            }
        }

        return ['costs' => (object) $bySrc, 'stays' => (object) $byStay, 'manual' => $manual];
    }

    /** أسماء ما اختاره العميل في كل مجموعة — للطباعة والعرض */
    public static function selectionLabels(Offer $o, array $sel): array
    {
        self::load($o);
        $out = [];
        foreach ($o->groups as $g) {
            $opt = $g->options->firstWhere('id', (int) ($sel[$g->id] ?? 0));
            if ($opt) {
                $out[] = ['group' => $g->label, 'option' => self::optionLabel($o, $g, $opt)];
            }
        }

        return $out;
    }

    /** مقاعد خيار محدود السعة (VIP) في الفواتير الحيّة */
    public static function optionUsage(Offer $o, int $optionId, ?int $ignoreInvoiceId = null): int
    {
        $used = 0;
        $invoices = $o->invoices()->whereIn('status', Offer::SEAT_STATUSES)
            ->when($ignoreInvoiceId, fn ($q) => $q->where('id', '!=', $ignoreInvoiceId))
            ->get(['id', 'offer_lines']);
        foreach ($invoices as $inv) {
            foreach (($inv->offer_lines['lines'] ?? []) as $l) {
                if (in_array($optionId, array_map('intval', array_values($l['sel'] ?? [])), true)) {
                    $used += (int) ($l['pax'] ?? 0);
                }
            }
        }

        return $used;
    }
}
