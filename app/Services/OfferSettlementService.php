<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\InvoiceItem;
use App\Models\Offer;
use App\Models\OfferSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إقفال العرض بعد العودة: مصاريف الرحلة المشتركة وفروق فواتير الموردين.
 *
 *  - المصاريف المشتركة: ما لا يخصّ مسافراً بعينه (باص مستأجر، مرشد، مشرف، أسرّة لم تُبع وحاسبنا عليها الفندق)،
 *    تُدخل هنا مرة واحدة للرحلة بمبلغها الفعلي ووكيلها وعملتها.
 *  - فرق فاتورة المورّد: كشفه الفعلي مقابل ما قُيِّد عليه من الفواتير. موجب = رسوم إضافية، سالب = خصم.
 *
 * تُسجَّل مصروفاً في «مصاريف الرحلات المشتركة» بقيد واحد، ولا تُخصم من ربح العرض الذي يبقى
 * مجموع أرباح فواتيره كما هو. ثم يُقفل العرض فيختفي من البيع والبوت.
 */
class OfferSettlementService
{
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
     * @param array $inputs ['shared' => [{agent_id, description, amount, currency}], 'bill' => [agentId => float]]
     */
    public static function preview(Offer $o, array $inputs = []): array
    {
        $invoices = $o->invoices()->whereIn('status', Offer::SEAT_STATUSES)->get(['id', 'status']);
        $pending = $invoices->where('status', '!=', 'approved')->count();

        // ما قُيِّد على كل وكيل من بنود الفواتير المعتمدة على العرض
        $posted = InvoiceItem::whereIn('invoice_id', $invoices->where('status', 'approved')->pluck('id'))
            ->whereNotNull('agent_id')->groupBy('agent_id')
            ->selectRaw('agent_id, sum(total_cost_jod) as posted')->pluck('posted', 'agent_id');

        $rows = [];
        $incomplete = 0;
        foreach (array_values((array) ($inputs['shared'] ?? [])) as $r) {
            $agent = !empty($r['agent_id']) ? (int) $r['agent_id'] : null;
            $label = trim((string) ($r['description'] ?? ''));
            $amount = round((float) ($r['amount'] ?? 0), 3);
            $cur = ($r['currency'] ?? 'JOD') === 'SAR' ? 'SAR' : 'JOD';
            if (!$agent && $label === '' && !$amount) {
                continue;
            }
            $ok = $agent && $label !== '' && $amount > 0;
            $incomplete += $ok ? 0 : 1;
            $rows[] = [
                'type' => 'shared',
                'agent_id' => $agent,
                'label' => $label,
                'amount' => $amount,
                'currency' => $cur,
                'diff' => $ok ? OfferPricing::toJod($amount, $cur) : 0.0,
            ];
        }
        $bill = (array) ($inputs['bill'] ?? []);
        foreach ($posted as $a => $p) {
            $rows[] = [
                'type' => 'bill',
                'agent_id' => (int) $a,
                'label' => 'فرق فاتورة المورّد الفعلية',
                'posted' => round((float) $p, 3),
                'diff' => round((float) ($bill[$a] ?? 0), 3),
            ];
        }

        $perAgent = [];
        foreach ($rows as $r) {
            if ($r['diff'] && $r['agent_id']) {
                $perAgent[$r['agent_id']] = round(($perAgent[$r['agent_id']] ?? 0) + $r['diff'], 3);
            }
        }

        $names = Agent::whereIn('id', collect($rows)->pluck('agent_id')->filter()->unique())->pluck('name', 'id');
        foreach ($rows as &$r) {
            $r['agent'] = $names[$r['agent_id']] ?? '—';
        }
        unset($r);

        return [
            'pending' => $pending,
            'is_back' => self::isBack($o),
            'incomplete' => $incomplete,
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
            throw ValidationException::withMessages(['settlement' => "في العرض {$p['pending']} فاتورة معلّقة أو تحت التعديل. أكمل تكاليفها واعتمدها، أو ارفضها، قبل الإقفال."]);
        }
        if (!$p['is_back']) {
            throw ValidationException::withMessages(['settlement' => 'الإقفال بعد عودة الرحلة (' . ($o->return_date ?: $o->trip_date)?->toDateString() . ').']);
        }
        if ($p['incomplete']) {
            throw ValidationException::withMessages(['settlement' => 'أكمل كل مصروف مشترك: الوكيل والبيان والمبلغ، أو احذفه.']);
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

            $rows = array_values(array_filter($p['rows'], fn ($r) => $r['diff'] && $r['agent_id']));
            $entry = AccountingService::recordOfferSettlement($settlement, $rows, $o->title);
            $settlement->update(['journal_entry_id' => $entry?->id]);

            // الدفتر الموازي (balance_sar) كما في اعتماد الفاتورة
            $rate = OfferPricing::rate();
            foreach ($p['per_agent'] as $a) {
                $agent = Agent::find($a['agent_id']);
                if (!$agent || !$a['diff']) {
                    continue;
                }
                $sar = round(abs($a['diff']) / $rate, 2);
                $a['diff'] > 0
                    ? BalanceService::debitAgent($agent, $sar, 'offer_settlement', $settlement->id)
                    : BalanceService::creditAgent($agent, $sar, 'offer_settlement', $settlement->id);
            }

            DB::table('offers')->where('id', $o->id)->update(['settlement_status' => 'approved']);
            AuditLog::log(\App\Enums\AuditAction::Settle, 'offer', $o->id, $o->title);

            return $settlement;
        });
    }
}
