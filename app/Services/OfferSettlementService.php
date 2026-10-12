<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\OfferCost;
use App\Models\OfferOption;
use App\Models\OfferSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إقفال العرض بعد العودة: الفرق بين ما قُيِّد على كل وكيل عبر الفواتير وما ندين به فعلاً.
 *
 *  - باقي البند المقطوع (الباص): المبلغ الكامل − ما قُيِّد مع المقاعد المباعة.
 *  - الأسرّة الشاغرة: (المحجوز − المباع) × تكلفة الفرد للإقامة (تكلفة الليلة × الليالي).
 *  - فرق فاتورة المورّد الفعلية: يُدخله المدير، موجباً أو سالباً.
 *
 * يُعتمد بقيد واحد على «تكاليف عروض غير مستردة»، ثم يُقفل العرض فيختفي من البيع والبوت.
 */
class OfferSettlementService
{
    public const CAPACITY = ['single' => 1, 'double' => 2, 'triple' => 3, 'quad' => 4];

    /** هل عادت الرحلة؟ الجاهز بعد تاريخ العودة (أو الرحلة إن لم يُحدَّد)، وغيره دائماً */
    public static function isBack(Offer $o): bool
    {
        if (!$o->is_ready) {
            return true;
        }
        $end = $o->return_date ?: $o->trip_date;

        return $end && $end->toDateString() < now()->toDateString();
    }

    /**
     * @param array $inputs ['allot' => ['stayId:room' => int], 'bill' => [agentId => float]]
     */
    public static function preview(Offer $o, array $inputs = []): array
    {
        $o->loadMissing(['groups.options.stays', 'groups.options.costs', 'fixedCosts']);
        $allotIn = (array) ($inputs['allot'] ?? []);
        $billIn = (array) ($inputs['bill'] ?? []);

        $invoices = $o->invoices()->whereIn('status', Offer::SEAT_STATUSES)->get(['id', 'status', 'offer_lines']);
        $pending = $invoices->where('status', '!=', 'approved')->count();
        $lines = $invoices->where('status', 'approved')->flatMap(fn ($i) => $i->offer_lines['lines'] ?? []);

        $rows = [];

        // البنود المقطوعة: الثابتة، وما على خيارات غير فندقية (باص)
        $groupCosts = $o->fixedCosts->map(fn ($c) => ['c' => $c, 'opt' => null])
            ->concat($o->groups->reject->isStay()->flatMap(fn ($g) => $g->options->flatMap(fn ($opt) => $opt->costs->map(fn ($c) => ['c' => $c, 'opt' => $opt])))->all())
            ->filter(fn ($x) => $x['c']->basis === 'per_group');

        foreach ($groupCosts as $x) {
            /** @var OfferCost $c */
            $c = $x['c'];
            $posted = 0.0;
            $seats = 0;
            foreach ($lines as $l) {
                foreach ($l['costs'] ?? [] as $lc) {
                    if (($lc['source_type'] ?? null) === 'cost' && (int) $lc['source_id'] === $c->id) {
                        $posted += round($lc['jod_pp'] * $l['pax'], 3);
                        $seats += (int) $l['pax'];
                    }
                }
            }
            $posted = round($posted, 3);
            $used = !$x['opt'] || $posted > 0;
            $full = $used ? OfferPricing::toJod((float) $c->qty * (float) $c->unit_cost, $c->currency) : 0.0;
            $rows[] = [
                'type' => 'group',
                'label' => $c->label . ($x['opt'] ? " ({$x['opt']->label})" : ''),
                'agent_id' => $c->agent_id,
                'expected' => $full,
                'posted' => $posted,
                'diff' => round($full - $posted, 3),
                'note' => $used ? "مستحق كاملاً؛ بيع {$seats} من " . (int) $o->total_seats : 'لم يُختر هذا الخيار فلا التزام',
            ];
        }

        // الأسرّة الشاغرة لكل فندق وسعة
        $map = [];
        foreach ($lines as $l) {
            foreach ($l['costs'] ?? [] as $lc) {
                if (($lc['source_type'] ?? null) !== 'stay' || empty($lc['room'])) {
                    continue;
                }
                $k = $lc['source_id'] . ':' . $lc['room'];
                $map[$k] ??= ['sold' => 0, 'posted' => 0.0, 'agent_id' => $lc['agent_id'], 'label' => $lc['label']];
                $map[$k]['sold'] += (int) $l['pax'];
                $map[$k]['posted'] = round($map[$k]['posted'] + $lc['jod_pp'] * $l['pax'], 3);
            }
        }
        $stays = $o->groups->filter->isStay()->flatMap(fn ($g) => $g->options->flatMap(fn ($opt) => $opt->stays->map(fn ($s) => ['g' => $g, 's' => $s])))->keyBy(fn ($x) => $x['s']->id);
        foreach ($map as $k => $v) {
            [$sid, $room] = explode(':', $k);
            $cap = self::CAPACITY[$room] ?? 1;
            $def = (int) (ceil($v['sold'] / $cap) * $cap);
            $allot = max($v['sold'], (int) ($allotIn[$k] ?? $def));
            $x = $stays->get((int) $sid);
            $pp = $x && $x['s']->rateFor($room) !== null
                ? OfferPricing::toJod($x['s']->rateFor($room) * OfferPricing::stayNights($o, $x['g'], $x['s']), $x['s']->currency)
                : ($v['sold'] ? round($v['posted'] / $v['sold'], 3) : 0);
            $rows[] = [
                'type' => 'vacancy',
                'key' => $k,
                'label' => ($x ? $x['s']->name : $v['label']) . ' · ' . (OfferOption::ROOM_TYPES[$room] ?? $room),
                'agent_id' => $v['agent_id'],
                'sold' => $v['sold'],
                'allot' => $allot,
                'default' => $def,
                'bed_cost' => $pp,
                'posted' => $v['posted'],
                'diff' => round(($allot - $v['sold']) * $pp, 3),
            ];
        }

        // فرق فاتورة المورّد لكل وكيل ظهر في العرض
        $agentIds = collect($rows)->pluck('agent_id')
            ->concat($lines->flatMap(fn ($l) => collect($l['costs'] ?? [])->pluck('agent_id')))
            ->filter()->unique()->values();
        foreach ($agentIds as $a) {
            $rows[] = ['type' => 'bill', 'agent_id' => (int) $a, 'label' => 'فرق فاتورة المورّد الفعلية', 'diff' => round((float) ($billIn[$a] ?? 0), 3)];
        }

        $perAgent = [];
        foreach ($rows as $r) {
            if ($r['diff'] && $r['agent_id']) {
                $perAgent[$r['agent_id']] = round(($perAgent[$r['agent_id']] ?? 0) + $r['diff'], 3);
            }
        }

        $names = Agent::whereIn('id', $agentIds)->pluck('name', 'id');
        foreach ($rows as &$r) {
            $r['agent'] = $names[$r['agent_id']] ?? '—';
        }
        unset($r);

        return [
            'pending' => $pending,
            'is_back' => self::isBack($o),
            'rows' => $rows,
            'per_agent' => collect($perAgent)->map(fn ($v, $a) => ['agent_id' => $a, 'agent' => $names[$a] ?? '—', 'diff' => $v])->values()->all(),
            'total' => round(array_sum($perAgent), 3),
        ];
    }

    public static function approve(Offer $o, array $inputs): OfferSettlement
    {
        if ($o->isClosed()) {
            throw ValidationException::withMessages(['settlement' => 'العرض مُقفل مسبقاً.']);
        }
        $p = self::preview($o, $inputs);
        if ($p['pending']) {
            throw ValidationException::withMessages(['settlement' => "في العرض {$p['pending']} فاتورة معلّقة أو تحت التعديل. اعتمدها أو ارفضها قبل الإقفال."]);
        }
        if (!$p['is_back']) {
            throw ValidationException::withMessages(['settlement' => 'الإقفال بعد عودة الرحلة (' . ($o->return_date ?: $o->trip_date)?->toDateString() . ').']);
        }

        return DB::transaction(function () use ($o, $p, $inputs) {
            $settlement = OfferSettlement::create([
                'offer_id' => $o->id,
                'lines' => $p['rows'],
                'inputs' => $inputs,
                'total_jod' => $p['total'],
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            $perAgent = collect($p['per_agent'])->pluck('diff', 'agent_id')->all();
            $entry = AccountingService::recordOfferSettlement($settlement, $perAgent, $o->title);
            $settlement->update(['journal_entry_id' => $entry?->id]);

            // الدفتر الموازي (balance_sar) كما في اعتماد الفاتورة
            $rate = OfferPricing::rate();
            foreach ($perAgent as $agentId => $diff) {
                $agent = Agent::find($agentId);
                if (!$agent || !$diff) {
                    continue;
                }
                $sar = round(abs($diff) / $rate, 2);
                $diff > 0
                    ? BalanceService::debitAgent($agent, $sar, 'offer_settlement', $settlement->id)
                    : BalanceService::creditAgent($agent, $sar, 'offer_settlement', $settlement->id);
            }

            DB::table('offers')->where('id', $o->id)->update(['settlement_status' => 'approved']);
            AuditLog::log('settle', 'offer', $o->id, $o->title);

            return $settlement;
        });
    }
}
