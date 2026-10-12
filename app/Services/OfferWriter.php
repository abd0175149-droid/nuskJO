<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Offer;
use App\Models\OfferCost;
use App\Models\OfferGroup;
use App\Models\OfferOption;
use App\Models\OfferStay;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * حفظ العرض بكل أجزائه: البيانات، والعرض الجاهز، والمجموعات والخيارات والفنادق والتكاليف.
 *
 * الحفظ تحديث بالمعرّف لا حذف وإعادة إنشاء، لأن الفواتير تشير إلى الخيارات
 * والفنادق والبنود. ما له مبيعات لا يُحذف ولا يتغير وكيله — يُعطَّل الخيار بدلاً من ذلك.
 * من لا يملك offers.view_cost لا يرى التكاليف، فلا تُمسّ تكاليف العرض من حفظه.
 */
class OfferWriter
{
    public static function rules(array $input): array
    {
        $isVisa = ($input['category'] ?? null) === 'visa';
        $isReady = !empty($input['is_ready']);
        $rooms = array_keys(OfferOption::ROOM_TYPES);

        $rules = [
            'title' => 'required|string|max:180',
            'category' => ['required', Rule::in(array_keys(Offer::CATEGORIES))],
            'description_client' => 'nullable|string|max:4000',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'includes' => 'nullable|array',
            'includes.*' => 'nullable|string|max:120',
            'excludes' => 'nullable|array',
            'excludes.*' => 'nullable|string|max:120',
            'notes_public' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'is_bot_visible' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:9999',

            'is_ready' => 'boolean',
            'trip_date' => [$isReady ? 'required' : 'nullable', 'date'],
            'return_date' => 'nullable|date|after_or_equal:trip_date',
            'travel_mode' => [$isReady ? 'required' : 'nullable', Rule::in(array_keys(Offer::TRAVEL_MODES))],
            'destination' => [$isReady ? 'required' : 'nullable', 'string', 'max:120'],
            'total_seats' => [$isReady ? 'required' : 'nullable', 'integer', 'min:1', 'max:5000'],

            'fixed_costs' => 'nullable|array|max:30',
        ];
        $rules += self::costRules('fixed_costs.*');

        if ($isVisa) {
            $rules += [
                'requirements' => 'nullable|string|max:4000',
                'visa_validity' => 'nullable|string|max:60',
                'visa_entries' => 'nullable|string|max:40',
                'visa_processing' => 'nullable|string|max:60',
                'price_per_person' => 'nullable|numeric|min:0|max:999999',
            ];
        } else {
            $rules += [
                'nights' => 'nullable|integer|min:0|max:365',
                'airline' => 'nullable|string|max:80',
                'route_mode' => ['nullable', Rule::in(array_keys(Offer::ROUTE_MODES))],
                'pricing_mode' => ['required', Rule::in(array_keys(Offer::PRICING_MODES))],
                'base_sell_pp' => 'nullable|numeric|min:0|max:999999',

                'groups' => 'required|array|min:1|max:10',
                'groups.*.id' => 'nullable|integer',
                'groups.*.kind' => ['required', Rule::in(array_keys(OfferGroup::KINDS))],
                'groups.*.label' => 'required|string|max:120',
                'groups.*.city' => 'nullable|string|max:60',
                'groups.*.nights' => 'nullable|integer|min:0|max:365',
                'groups.*.service_id' => 'nullable|integer',
                'groups.*.options' => 'nullable|array|max:20',
                'groups.*.options.*.id' => 'nullable|integer',
                'groups.*.options.*.label' => 'nullable|string|max:120',
                'groups.*.options.*.includes_note' => 'nullable|string|max:500',
                'groups.*.options.*.prices' => 'nullable|array',
                'groups.*.options.*.sell_flat' => 'nullable|numeric|min:-99999|max:999999',
                'groups.*.options.*.capacity' => 'nullable|integer|min:1|max:5000',
                'groups.*.options.*.is_active' => 'boolean',
                'groups.*.options.*.stays' => 'nullable|array|max:4',
                'groups.*.options.*.stays.*.id' => 'nullable|integer',
                'groups.*.options.*.stays.*.city' => 'nullable|string|max:60',
                'groups.*.options.*.stays.*.name' => 'required|string|max:150',
                'groups.*.options.*.stays.*.rating' => 'nullable|integer|min:1|max:7',
                'groups.*.options.*.stays.*.rating_plus' => 'boolean',
                'groups.*.options.*.stays.*.location' => 'nullable|string|max:80',
                'groups.*.options.*.stays.*.meals' => 'nullable|string|max:60',
                'groups.*.options.*.stays.*.distance_haram' => 'nullable|string|max:40',
                'groups.*.options.*.stays.*.nights' => 'nullable|integer|min:0|max:365',
                'groups.*.options.*.stays.*.agent_id' => 'nullable|integer|exists:agents,id',
                'groups.*.options.*.stays.*.currency' => ['nullable', Rule::in(['SAR', 'JOD'])],
                'groups.*.options.*.stays.*.room_rates' => 'nullable|array',
                'groups.*.options.*.costs' => 'nullable|array|max:10',
            ];
            foreach ($rooms as $r) {
                $rules["groups.*.options.*.prices.{$r}"] = 'nullable|numeric|min:0|max:999999';
                $rules["groups.*.options.*.stays.*.room_rates.{$r}"] = 'nullable|numeric|min:0|max:999999';
            }
            $rules += self::costRules('groups.*.options.*.costs.*');
        }

        return $rules;
    }

    private static function costRules(string $p): array
    {
        return [
            "{$p}.id" => 'nullable|integer',
            "{$p}.kind" => ['required', Rule::in(array_keys(OfferCost::KINDS))],
            "{$p}.label" => 'required|string|max:150',
            "{$p}.agent_id" => 'nullable|integer|exists:agents,id',
            "{$p}.service_id" => 'nullable|integer',
            "{$p}.basis" => ['required', Rule::in(array_keys(OfferCost::BASES))],
            "{$p}.qty" => 'nullable|numeric|min:0|max:9999',
            "{$p}.unit_cost" => 'nullable|numeric|min:0|max:9999999',
            "{$p}.currency" => ['required', Rule::in(['SAR', 'JOD'])],
        ];
    }

    public static function messages(): array
    {
        return [
            'groups.required' => 'أضف مجموعة فنادق واحدة على الأقل.',
            'groups.*.label.required' => 'اسم المجموعة مطلوب.',
            'groups.*.options.*.stays.*.name.required' => 'اسم الفندق مطلوب.',
            'trip_date.required' => 'تاريخ الرحلة مطلوب للعرض الجاهز.',
            'travel_mode.required' => 'وسيلة السفر مطلوبة للعرض الجاهز.',
            'destination.required' => 'الوجهة مطلوبة للعرض الجاهز.',
            'total_seats.required' => 'عدد المقاعد مطلوب للعرض الجاهز.',
            'fixed_costs.*.label.required' => 'اسم البند مطلوب.',
            'groups.*.options.*.costs.*.label.required' => 'اسم البند مطلوب.',
        ];
    }

    /** ما بيع من العرض: مصادر البنود والخيارات في الفواتير غير المرفوضة */
    public static function soldRefs(Offer $offer): array
    {
        $refs = ['stay' => [], 'cost' => [], 'option' => []];
        if (!$offer->exists) {
            return $refs;
        }
        $invoices = Invoice::where('offer_id', $offer->id)->where('status', '!=', 'rejected')->get(['id', 'offer_lines']);
        foreach ($invoices as $inv) {
            foreach (($inv->offer_lines['lines'] ?? []) as $l) {
                foreach (array_values($l['sel'] ?? []) as $optId) {
                    $refs['option'][(int) $optId] = true;
                }
                foreach (($l['costs'] ?? []) as $c) {
                    if (in_array($c['source_type'] ?? null, ['stay', 'cost'], true)) {
                        $refs[$c['source_type']][(int) $c['source_id']] = (int) ($c['agent_id'] ?? 0);
                    }
                }
            }
        }

        return $refs;
    }

    /** يحفظ العرض ويعيده. يرمي ValidationException برسائل عربية واضحة */
    public static function save(?Offer $offer, array $data, bool $canCost): Offer
    {
        $offer ??= new Offer();
        $isNew = !$offer->exists;
        $isVisa = $data['category'] === 'visa';
        $sold = self::soldRefs($offer);
        $hasSales = !$isNew && Invoice::where('offer_id', $offer->id)->where('status', '!=', 'rejected')->exists();
        $errors = [];

        if ($hasSales) {
            if ($offer->category !== $data['category'] && ($offer->isVisa() || $isVisa)) {
                $errors['category'] = 'للعرض مبيعات، فلا يتحوّل من تأشيرة وإليها.';
            }
            if (!$isVisa && ($data['pricing_mode'] ?? $offer->pricing_mode) !== $offer->pricing_mode) {
                $errors['pricing_mode'] = 'للعرض مبيعات، فلا يتغير نمط الفنادق.';
            }
        }
        if (!$isNew) {
            $live = $offer->bookedSeats();
            if (!empty($data['is_ready']) && (int) ($data['total_seats'] ?? 0) < $live) {
                $errors['total_seats'] = "لا تقلّ المقاعد عن المحجوز في الفواتير ({$live}).";
            }
            if ($offer->is_ready && empty($data['is_ready']) && $live > 0) {
                $errors['is_ready'] = 'للعرض فواتير قائمة بمقاعد، فلا يُلغى كونه جاهزاً.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $fields = [
            'title' => trim($data['title']),
            'category' => $data['category'],
            'description_client' => $data['description_client'] ?? null,
            'notes_public' => $data['notes_public'] ?? null,
            'includes' => array_values(array_filter(array_map('trim', array_map('strval', $data['includes'] ?? [])))),
            'excludes' => array_values(array_filter(array_map('trim', array_map('strval', $data['excludes'] ?? [])))),
            'valid_from' => $data['valid_from'] ?? null,
            'valid_to' => $data['valid_to'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_bot_visible' => (bool) ($data['is_bot_visible'] ?? false),
            'sort_order' => $data['sort_order'] ?? 0,
            'is_ready' => (bool) ($data['is_ready'] ?? false),
            'trip_date' => !empty($data['is_ready']) ? $data['trip_date'] : null,
            'return_date' => !empty($data['is_ready']) ? ($data['return_date'] ?? null) : null,
            'travel_mode' => !empty($data['is_ready']) ? $data['travel_mode'] : null,
            'destination' => !empty($data['is_ready']) ? $data['destination'] : null,
            'total_seats' => $data['total_seats'] ?? null,
        ];
        if ($isVisa) {
            $fields += [
                'requirements' => $data['requirements'] ?? null,
                'visa_validity' => $data['visa_validity'] ?? null,
                'visa_entries' => $data['visa_entries'] ?? null,
                'visa_processing' => $data['visa_processing'] ?? null,
                'price_per_person' => $data['price_per_person'] ?? null,
                'nights' => null, 'airline' => null, 'route_mode' => null,
                'pricing_mode' => 'bundle', 'base_sell_pp' => 0,
            ];
        } else {
            $fields += [
                'nights' => $data['nights'] ?? null,
                'airline' => $data['airline'] ?? null,
                'route_mode' => in_array($data['category'], ['umrah', 'hajj'], true) ? ($data['route_mode'] ?? null) : null,
                'pricing_mode' => $data['pricing_mode'],
                'base_sell_pp' => round((float) ($data['base_sell_pp'] ?? 0), 3),
                'requirements' => null, 'visa_validity' => null, 'visa_entries' => null,
                'visa_processing' => null, 'price_per_person' => null,
            ];
        }

        return DB::transaction(function () use ($offer, $isNew, $fields, $data, $canCost, $isVisa, $sold) {
            if ($isNew) {
                $fields['created_by'] = auth()->id();
            }
            $offer->fill($fields)->save();

            $errors = [];
            $keep = ['group' => [], 'option' => [], 'stay' => [], 'cost' => []];

            if ($canCost) {
                self::syncCosts($offer, null, $data['fixed_costs'] ?? [], $sold, $keep, $errors, 'fixed_costs');
            }

            if (!$isVisa) {
                foreach (array_values($data['groups'] ?? []) as $gi => $g) {
                    $group = self::find(OfferGroup::class, $g['id'] ?? null, ['offer_id' => $offer->id]) ?? new OfferGroup(['offer_id' => $offer->id]);
                    $group->fill([
                        'offer_id' => $offer->id,
                        'kind' => $g['kind'],
                        'label' => trim($g['label']),
                        'city' => $data['pricing_mode'] === 'city' ? ($g['city'] ?? null) : null,
                        'nights' => $g['nights'] ?? null,
                        'service_id' => $g['service_id'] ?? null,
                        'sort_order' => $gi,
                    ])->save();
                    $keep['group'][] = $group->id;

                    foreach (array_values($g['options'] ?? []) as $oi => $o) {
                        $opt = self::find(OfferOption::class, $o['id'] ?? null, ['offer_id' => $offer->id]) ?? new OfferOption(['offer_id' => $offer->id]);
                        $prices = [];
                        foreach (array_keys(OfferOption::ROOM_TYPES) as $r) {
                            $v = $o['prices'][$r] ?? null;
                            $prices[$r] = ($v === null || $v === '') ? null : round((float) $v, 3);
                        }
                        $opt->fill([
                            'offer_id' => $offer->id,
                            'group_id' => $group->id,
                            'label' => isset($o['label']) ? trim((string) $o['label']) ?: null : null,
                            'includes_note' => $o['includes_note'] ?? null,
                            'prices' => $group->isStay() ? $prices : array_fill_keys(array_keys(OfferOption::ROOM_TYPES), null),
                            'sell_flat' => $group->isStay() ? 0 : round((float) ($o['sell_flat'] ?? 0), 3),
                            'capacity' => $o['capacity'] ?? null,
                            'is_active' => (bool) ($o['is_active'] ?? true),
                            'sort_order' => $oi,
                        ])->save();
                        $keep['option'][] = $opt->id;

                        if ($group->isStay()) {
                            foreach (array_values($o['stays'] ?? []) as $si => $s) {
                                $stay = self::find(OfferStay::class, $s['id'] ?? null, ['option_id' => $opt->id]) ?? new OfferStay(['option_id' => $opt->id]);
                                $fill = [
                                    'option_id' => $opt->id,
                                    'city' => $data['pricing_mode'] === 'city' ? $group->city : ($s['city'] ?? null),
                                    'name' => trim($s['name']),
                                    'rating' => $s['rating'] ?? null,
                                    'rating_plus' => (bool) ($s['rating_plus'] ?? false),
                                    'location' => $s['location'] ?? null,
                                    'meals' => $s['meals'] ?? null,
                                    'distance_haram' => in_array($data['category'], ['umrah', 'hajj'], true) ? ($s['distance_haram'] ?? null) : null,
                                    'nights' => $s['nights'] ?? null,
                                    'sort_order' => $si,
                                ];
                                if ($canCost) {
                                    $rates = [];
                                    foreach (array_keys(OfferOption::ROOM_TYPES) as $r) {
                                        $v = $s['room_rates'][$r] ?? null;
                                        $rates[$r] = ($v === null || $v === '') ? null : round((float) $v, 3);
                                    }
                                    $agentId = !empty($s['agent_id']) ? (int) $s['agent_id'] : null;
                                    if ($stay->exists && isset($sold['stay'][$stay->id]) && (int) $stay->agent_id !== (int) $agentId) {
                                        $errors["stay.{$stay->id}"] = "«{$stay->name}» له مبيعات ولا يُغيَّر وكيله. عطّل الخيار وأضف بديلاً بالوكيل الجديد.";
                                    }
                                    $fill += ['agent_id' => $agentId, 'room_rates' => $rates, 'currency' => $s['currency'] ?? 'SAR'];
                                }
                                $stay->fill($fill)->save();
                                $keep['stay'][] = $stay->id;
                            }
                        } elseif ($canCost) {
                            self::syncCosts($offer, $opt, $o['costs'] ?? [], $sold, $keep, $errors, "option.{$opt->id}");
                        } else {
                            $keep['cost'] = array_merge($keep['cost'], $opt->costs()->pluck('id')->all());
                        }
                    }
                }
            }

            if (!$canCost) {
                $keep['cost'] = array_merge($keep['cost'], $offer->fixedCosts()->pluck('id')->all());
            }

            // الحذف: ما لم يرد في الحفظ — إلا ما له مبيعات
            $optionIds = OfferOption::where('offer_id', $offer->id)->pluck('id');
            $stayRows = OfferStay::whereIn('option_id', $optionIds)->get(['id', 'name']);
            foreach ($stayRows as $s) {
                if (!in_array($s->id, $keep['stay'], true)) {
                    if (isset($sold['stay'][$s->id])) {
                        $errors["stay.{$s->id}"] = "«{$s->name}» له مبيعات ولا يُحذف. عطّل خياره بدلاً من ذلك.";
                    } else {
                        $s->delete();
                    }
                }
            }
            foreach (OfferCost::where('offer_id', $offer->id)->get(['id', 'label']) as $c) {
                if (!in_array($c->id, $keep['cost'], true)) {
                    if (isset($sold['cost'][$c->id])) {
                        $errors["cost.{$c->id}"] = "«{$c->label}» له مبيعات ولا يُحذف. عطّل خياره بدلاً من ذلك.";
                    } else {
                        $c->delete();
                    }
                }
            }
            foreach (OfferOption::where('offer_id', $offer->id)->get(['id', 'label']) as $o) {
                if (!in_array($o->id, $keep['option'], true)) {
                    if (isset($sold['option'][$o->id])) {
                        $errors["option.{$o->id}"] = 'خيار له مبيعات لا يُحذف. ألغِ «فعّال» ليختفي من الفاتورة والبوت ويبقى للفواتير القديمة.';
                    } else {
                        OfferStay::where('option_id', $o->id)->delete();
                        OfferCost::where('option_id', $o->id)->delete();
                        $o->delete();
                    }
                }
            }
            foreach (OfferGroup::where('offer_id', $offer->id)->get(['id']) as $g) {
                if (!in_array($g->id, $keep['group'], true) && !OfferOption::where('group_id', $g->id)->exists()) {
                    $g->delete();
                }
            }

            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            return $offer->fresh();
        });
    }

    private static function syncCosts(Offer $offer, ?OfferOption $opt, array $rows, array $sold, array &$keep, array &$errors, string $where): void
    {
        foreach (array_values($rows) as $ci => $c) {
            $cost = self::find(OfferCost::class, $c['id'] ?? null, ['offer_id' => $offer->id]) ?? new OfferCost(['offer_id' => $offer->id]);
            $agentId = !empty($c['agent_id']) ? (int) $c['agent_id'] : null;
            if ($cost->exists && isset($sold['cost'][$cost->id]) && (int) $cost->agent_id !== (int) $agentId) {
                $errors["cost.{$cost->id}"] = "«{$cost->label}» له مبيعات ولا يُغيَّر وكيله. عطّل الخيار وأضف بديلاً بالوكيل الجديد.";
            }
            $cost->fill([
                'offer_id' => $offer->id,
                'option_id' => $opt?->id,
                'kind' => $c['kind'],
                'label' => trim($c['label']),
                'agent_id' => $agentId,
                'service_id' => $c['service_id'] ?? null,
                'basis' => $c['basis'],
                'qty' => (float) ($c['qty'] ?? 1),
                'unit_cost' => round((float) ($c['unit_cost'] ?? 0), 3),
                'currency' => $c['currency'],
                'sort_order' => $ci,
            ])->save();
            $keep['cost'][] = $cost->id;
        }
    }

    /** سجل بمعرّفه إن كان ينتمي لهذا العرض — لا يُقبل معرّف من عرض آخر */
    private static function find(string $class, $id, array $scope)
    {
        if (!$id) {
            return null;
        }

        return $class::where('id', (int) $id)->where($scope)->first();
    }
}
