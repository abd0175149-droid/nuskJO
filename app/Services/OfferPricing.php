<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\OfferCost;
use App\Models\OfferGroup;
use App\Models\OfferOption;
use App\Models\OfferStay;
use App\Models\Service;

/**
 * المصدر الوحيد لحساب سعر فاتورة العرض وتكلفتها.
 *
 * يستخدمه نموذج العرض (اكتمال التكاليف ومصفوفة الهامش)، ومعاينة الفاتورة،
 * وحفظ الفاتورة نفسها — فلا يوجد حساب ثانٍ في الواجهة يمكن أن يختلف عنه.
 *
 * أسس التكلفة:
 *  - الفندق: تكلفة الفرد لليلة حسب سعة الغرفة × عدد الليالي.
 *  - للفرد: العدد × تكلفة الوحدة.
 *  - مقطوع للرحلة: (العدد × تكلفة الوحدة) ÷ مقاعد العرض لكل مقعد، والباقي عند الإقفال.
 * كل تكلفة بعملة وكيلها، وتُحوَّل للدينار بسعر واحد من config/accounting.php.
 */
class OfferPricing
{
    public const ROOMS = ['single', 'double', 'triple', 'quad'];

    /** خدمة الإيراد الافتراضية لكل نوع بند حين لا يحدّدها المدير */
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
        $o->loadMissing(['groups.options.stays', 'groups.options.costs', 'fixedCosts']);
    }

    // ==================== اكتمال التكاليف ====================

    /** ما يمنع بيع العرض بفاتورة عرض. مصفوفة فارغة = جاهز للبيع */
    public static function issues(Offer $o): array
    {
        self::load($o);
        $out = [];

        $chk = function (OfferCost $c, string $where) use (&$out) {
            $n = $c->label ?: $where;
            if (!$c->agent_id) $out[] = "«{$n}»: الوكيل مطلوب.";
            if (!((float) $c->unit_cost > 0)) $out[] = "«{$n}»: التكلفة مطلوبة.";
            if (!((float) $c->qty > 0)) $out[] = "«{$n}»: العدد مطلوب.";
        };

        $perGroup = $o->fixedCosts->contains('basis', 'per_group')
            || $o->groups->flatMap->options->flatMap->costs->contains('basis', 'per_group');
        if (($o->is_ready || $perGroup) && !((int) $o->total_seats >= 1)) {
            $out[] = $perGroup ? 'عدد المقاعد مطلوب لتوزيع البنود المقطوعة.' : 'عدد المقاعد مطلوب للعرض الجاهز.';
        }

        foreach ($o->fixedCosts as $c) {
            $chk($c, 'بند ثابت');
        }

        if ($o->isVisa()) {
            if (!($o->basePrice() > 0)) $out[] = 'سعر التأشيرة للفرد مطلوب.';
            if ($o->fixedCosts->isEmpty()) $out[] = 'أضف بند تكلفة التأشيرة بوكيله.';

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

            foreach ($active as $opt) {
                $lab = self::optionLabel($o, $g, $opt);
                if ($g->isStay()) {
                    $sellRooms = array_values(array_filter(self::ROOMS, fn ($r) => $opt->sellFor($r) !== null));
                    if (!$sellRooms) $out[] = "«{$lab}»: لا سعر بيع لأي سعة.";
                    if ($opt->stays->isEmpty()) $out[] = "«{$lab}»: أضف فندقاً.";
                    foreach ($opt->stays as $s) {
                        $n = $s->name ?: 'فندق';
                        if (!$s->agent_id) $out[] = "«{$n}»: الوكيل مطلوب.";
                        if (!(self::stayNights($o, $g, $s) > 0)) $out[] = "«{$n}»: عدد الليالي مطلوب.";
                        foreach ($sellRooms as $r) {
                            if ($s->rateFor($r) === null) {
                                $out[] = "«{$n}»: تكلفة الفرد لليلة في الغرفة " . OfferOption::ROOM_TYPES[$r] . ' مطلوبة لأنها تُباع.';
                            }
                        }
                    }
                } else {
                    if ($opt->costs->isEmpty()) $out[] = "«{$lab}»: بلا بند تكلفة.";
                    foreach ($opt->costs as $c) {
                        $chk($c, $lab);
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** تنبيهات لا تمنع البيع */
    public static function warnings(Offer $o): array
    {
        self::load($o);
        $out = [];
        $includes = array_values(array_filter($o->includes ?: []));
        $nonHotel = $o->fixedCosts->isNotEmpty() || $o->groups->reject->isStay()->isNotEmpty();
        if (!$o->isVisa() && $includes && !$nonHotel) {
            $out[] = '«يشمل» فيه ' . count($includes) . ' بنود (' . implode('، ', $includes) . ') ولا بند تكلفة غير فندقي. إن كانت لها تكلفة فسجّلها، وإلا ظهر ربح العرض أعلى من حقيقته.';
        }

        return $out;
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
     * سعر الفرد وتكلفته لاختيار واحد وسعة واحدة.
     *
     * @param array $sel [group_id => option_id]
     * @return array{errors: array, parts: array, costs: array, sell_pp: float, cost_pp: float}
     */
    public static function priceLine(Offer $o, array $sel, string $room): array
    {
        self::load($o);
        $errors = $parts = $costs = [];

        if ($o->basePrice() > 0) {
            $parts[] = ['label' => $o->isVisa() ? 'سعر التأشيرة' : 'السعر الأساسي', 'pp' => round($o->basePrice(), 3)];
        }
        foreach ($o->fixedCosts as $c) {
            $costs[] = self::costLine($o, $c);
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

                foreach ($opt->stays as $s) {
                    $rate = $s->rateFor($room);
                    $nights = self::stayNights($o, $g, $s);
                    $city = self::stayCity($o, $g, $s);
                    if ($rate === null) {
                        $errors[] = 'لا تكلفة غرفة ' . OfferOption::ROOM_TYPES[$room] . " لـ «{$s->name}».";
                        continue;
                    }
                    $orig = $rate * $nights;
                    $costs[] = [
                        'source_type' => 'stay',
                        'source_id' => $s->id,
                        'kind' => 'hotel',
                        'label' => $s->name . ($city ? " ({$city})" : ''),
                        'description' => $s->name . ($city ? " ({$city})" : '') . ' · ' . OfferOption::ROOM_TYPES[$room] . " · {$nights} " . ($nights >= 3 && $nights <= 10 ? 'ليالٍ' : 'ليلة'),
                        'agent_id' => $s->agent_id,
                        'service_id' => self::serviceFor($g->service_id, 'hotel', $city),
                        'currency' => $s->currency ?: 'SAR',
                        'basis' => 'room_night',
                        'calc' => self::num($rate) . ' × ' . $nights . ' ' . ($s->currency === 'JOD' ? 'د.أ' : 'ريال'),
                        'original_pp' => round($orig, 3),
                        'jod_pp' => self::toJod($orig, $s->currency),
                        'room' => $room,
                        'nights' => $nights,
                    ];
                }
            } else {
                if ((float) $opt->sell_flat) {
                    $parts[] = ['label' => "{$g->label}: {$opt->label}", 'pp' => round((float) $opt->sell_flat, 3)];
                }
                foreach ($opt->costs as $c) {
                    $costs[] = self::costLine($o, $c);
                }
            }
        }

        foreach ($costs as $c) {
            if (!$c['agent_id']) {
                $errors[] = "«{$c['label']}» بلا وكيل.";
            }
        }

        return [
            'errors' => array_values(array_unique($errors)),
            'parts' => $parts,
            'costs' => $costs,
            'sell_pp' => round(array_sum(array_column($parts, 'pp')), 3),
            'cost_pp' => round(array_sum(array_column($costs, 'jod_pp')), 3),
        ];
    }

    private static function costLine(Offer $o, OfferCost $c): array
    {
        $qty = (float) $c->qty;
        $unit = (float) $c->unit_cost;
        $cur = $c->currency ?: 'JOD';
        $curL = $cur === 'SAR' ? 'ريال' : 'د.أ';
        if ($c->basis === 'per_group') {
            $orig = (int) $o->total_seats > 0 ? $qty * $unit / (int) $o->total_seats : 0;
            $calc = self::num($qty * $unit) . " {$curL} ÷ " . (int) $o->total_seats;
        } else {
            $orig = $qty * $unit;
            $calc = self::num($qty) . ' × ' . self::num($unit) . " {$curL}";
        }

        return [
            'source_type' => 'cost',
            'source_id' => $c->id,
            'kind' => $c->kind,
            'label' => $c->label ?: (OfferCost::KINDS[$c->kind] ?? 'بند'),
            'description' => $c->label ?: (OfferCost::KINDS[$c->kind] ?? 'بند'),
            'agent_id' => $c->agent_id,
            'service_id' => self::serviceFor($c->service_id, $c->kind),
            'currency' => $cur,
            'basis' => $c->basis,
            'calc' => $calc,
            'original_pp' => round($orig, 3),
            'jod_pp' => self::toJod($orig, $cur),
            'room' => null,
            'nights' => null,
        ];
    }

    /** السعات المتاحة لاختيار: لها سعر بيع وتكلفة في كل فندق مختار */
    public static function roomsFor(Offer $o, array $sel): array
    {
        self::load($o);
        $stayGroups = $o->groups->filter->isStay();

        return array_values(array_filter(self::ROOMS, function ($r) use ($stayGroups, $sel) {
            foreach ($stayGroups as $g) {
                $opt = $g->options->firstWhere('id', (int) ($sel[$g->id] ?? 0));
                if (!$opt || $opt->sellFor($r) === null || $opt->stays->contains(fn ($s) => $s->rateFor($r) === null)) {
                    return false;
                }
            }

            return true;
        }));
    }

    // ==================== الفاتورة ====================

    public static function lineKey(array $sel, string $room): string
    {
        ksort($sel);

        return json_encode(array_map('intval', $sel)) . '|' . $room;
    }

    /**
     * يبني فاتورة عرض: السطور (لقطة)، وبنود التكلفة لكل وكيل، والإجماليات.
     *
     * @param array $lines [{sel: {group_id: option_id}, room, pax}]
     * @param array $extras [{agent_id, description, qty, cost, sell}] بنود يدوية خارج العرض
     * @param array $previous سطور اللقطة السابقة للفاتورة — تُحفظ تكاليفها ما لم يتغير الاختيار
     */
    public static function build(Offer $o, array $lines, array $extras = [], float $discount = 0, array $previous = [], bool $refresh = false): array
    {
        self::load($o);
        $errors = [];
        $prevByKey = collect($previous)->keyBy('key');
        $stay = $o->groups->filter->isStay()->isNotEmpty();

        $built = [];
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
            if ($prev && !$refresh) {
                $pr = ['errors' => [], 'parts' => $prev['parts'], 'costs' => $prev['costs'], 'sell_pp' => $prev['sell_pp'], 'cost_pp' => $prev['cost_pp']];
            } else {
                $pr = self::priceLine($o, $sel, $room);
            }
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
                'costs' => $pr['costs'],
                'sell_pp' => $pr['sell_pp'],
                'cost_pp' => $pr['cost_pp'],
                'kept' => (bool) ($prev && !$refresh),
            ];
        }

        $items = [];
        foreach ($built as $l) {
            foreach ($l['costs'] as $c) {
                $items[] = [
                    'agent_id' => $c['agent_id'],
                    'service_id' => $c['service_id'],
                    'description' => $c['description'],
                    'statement' => $o->title,
                    'quantity' => $l['pax'],
                    'unit_price_jod' => $c['jod_pp'],
                    'total_cost_jod' => round($c['jod_pp'] * $l['pax'], 3),
                    'source_type' => $c['source_type'],
                    'source_id' => $c['source_id'],
                    'cost_currency' => $c['currency'],
                    'cost_original' => round($c['original_pp'] * $l['pax'], 3),
                    'fx_rate' => $c['currency'] === 'SAR' ? self::rate() : 1,
                    'room' => $c['room'],
                ];
            }
        }

        $extraSell = 0.0;
        $cleanExtras = [];
        foreach (array_values($extras) as $i => $e) {
            $n = $i + 1;
            $q = (int) ($e['qty'] ?? 0);
            $cost = round((float) ($e['cost'] ?? 0), 3);
            $sell = round((float) ($e['sell'] ?? 0), 3);
            $desc = trim((string) ($e['description'] ?? ''));
            if (empty($e['agent_id'])) $errors[] = "البند الإضافي {$n}: الوكيل مطلوب.";
            if ($desc === '') $errors[] = "البند الإضافي {$n}: البيان مطلوب.";
            if ($q < 1) $errors[] = "البند الإضافي {$n}: العدد 1 أو أكثر.";
            $items[] = [
                'agent_id' => !empty($e['agent_id']) ? (int) $e['agent_id'] : null,
                'service_id' => !empty($e['service_id']) ? (int) $e['service_id'] : null,
                'description' => $desc ?: 'بند إضافي',
                'statement' => 'خارج العرض',
                'quantity' => max(0, $q),
                'unit_price_jod' => $cost,
                'total_cost_jod' => round($cost * max(0, $q), 3),
                'source_type' => 'manual',
                'source_id' => null,
                'cost_currency' => 'JOD',
                'cost_original' => round($cost * max(0, $q), 3),
                'fx_rate' => 1,
                'room' => null,
            ];
            $extraSell += $sell * max(0, $q);
            $cleanExtras[] = ['agent_id' => $e['agent_id'] ?? null, 'service_id' => $e['service_id'] ?? null, 'description' => $desc, 'qty' => $q, 'cost' => $cost, 'sell' => $sell];
        }

        $lineSell = array_sum(array_map(fn ($l) => round($l['sell_pp'] * $l['pax'], 3), $built));
        $gross = round($lineSell + $extraSell, 3);
        $discount = round(max(0, $discount), 3);
        if ($discount > $gross) {
            $errors[] = 'الخصم أكبر من إجمالي البيع.';
        }
        $sell = round($gross - $discount, 3);
        $cost = round(array_sum(array_column($items, 'total_cost_jod')), 3);

        return [
            'errors' => array_values(array_unique($errors)),
            'lines' => $built,
            'extras' => $cleanExtras,
            'items' => $items,
            'gross' => $gross,
            'discount' => $discount,
            'sell' => $sell,
            'cost' => $cost,
            'profit' => round($sell - $cost, 3),
            'pax' => array_sum(array_column($built, 'pax')),
        ];
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

    // ==================== مصفوفة الهامش ====================

    /** هامش كل تركيبة لكل سعة — يراها المدير قبل البيع */
    public static function marginMatrix(Offer $o, int $limit = 24): array
    {
        self::load($o);
        if ($o->isVisa()) {
            $r = self::priceLine($o, [], 'single');

            return [['label' => 'التأشيرة', 'cells' => ['single' => self::cell($r)]]];
        }

        $acc = [[]];
        foreach ($o->groups as $g) {
            $active = $g->activeOptions();
            if ($active->isEmpty()) {
                return [];
            }
            $next = [];
            foreach ($acc as $sel) {
                foreach ($active as $opt) {
                    $next[] = $sel + [$g->id => $opt->id];
                }
            }
            $acc = array_slice($next, 0, $limit);
        }

        return array_map(function ($sel) use ($o) {
            $label = collect(self::selectionLabels($o, $sel))->pluck('option')->implode(' · ');
            $cells = [];
            foreach (self::ROOMS as $room) {
                $cells[$room] = self::cell(self::priceLine($o, $sel, $room));
            }

            return ['label' => $label, 'cells' => $cells];
        }, $acc);
    }

    private static function cell(array $r): ?array
    {
        if ($r['errors'] || !$r['sell_pp']) {
            return null;
        }
        $profit = round($r['sell_pp'] - $r['cost_pp'], 3);

        return ['sell' => $r['sell_pp'], 'cost' => $r['cost_pp'], 'profit' => $profit, 'margin' => round($profit / $r['sell_pp'], 4)];
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ','), '0'), '.');
    }
}
