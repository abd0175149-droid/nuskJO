<?php

namespace App\Observers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\QuoteRequest;
use App\Models\Receipt;
use App\Services\WhatsApp\Sending\Outbox;
use App\Services\WhatsApp\System\Scheduler;
use App\Services\WhatsApp\WaLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * أحداث المجال إلى رسائل النظام (System\Scheduler)، بعد اعتماد المعاملة وحدها (DB::afterCommit): اعتماد فاتورة أو سند،
 * وتغيّر تاريخ السفر أو الاستحقاق، ورفض أو حذف، وعميل جديد، وتسعير طلب. لا يرمي أبداً: فشل الجدولة يُسجَّل، ولا يُسقط
 * الاعتماد المحاسبي، والمسح الدوري (wa:schedule-system) يلتقط ما فات.
 */
class WhatsAppSystemObserver
{
    public function created(Model $m): void
    {
        if ($m instanceof Client && filled($m->phone)) {
            self::later(fn () => Scheduler::schedule('client.welcome', 'client', (int) $m->id, Scheduler::soon('client.welcome')));
        }
    }

    public function updated(Model $m): void
    {
        match (true) {
            $m instanceof Invoice => $this->invoice($m),
            $m instanceof Receipt => $this->receipt($m),
            $m instanceof QuoteRequest => $this->quote($m),
            default => null,
        };
    }

    public function deleted(Model $m): void
    {
        $type = match (true) {
            $m instanceof Invoice => 'invoice',
            $m instanceof Receipt => 'receipt',
            $m instanceof QuoteRequest => 'quote',
            $m instanceof Client => 'client',
            default => null,
        };
        if ($type !== null) {
            self::later(fn () => Outbox::cancelFor($type, (int) $m->getKey(), 'حُذف المصدر'));
        }
    }

    private function invoice(Invoice $inv): void
    {
        $becameApproved = $inv->wasChanged('status') && $inv->status === 'approved';
        $datesChanged = $inv->wasChanged(['trip_date', 'due_date', 'offer_id']);
        if ($inv->wasChanged('status') && $inv->status !== 'approved') {
            self::later(fn () => Outbox::cancelFor('invoice', (int) $inv->id, 'الفاتورة لم تعد معتمدة'));

            return;
        }
        if (!$becameApproved && !($datesChanged && $inv->status === 'approved')) {
            return;
        }
        self::later(function () use ($inv, $becameApproved) {
            $fresh = Invoice::with('offer:id,return_date')->find($inv->id);
            if (!$fresh) {
                return;
            }
            // الفاتورة المعدّلة بعد اعتماد سابق تعود معتمدة: نسخة جديدة بمفتاح وقت الاعتماد
            if ($becameApproved) {
                Scheduler::schedule('invoice.approved', 'invoice', (int) $fresh->id, Scheduler::soon('invoice.approved'),
                    (string) ($fresh->approved_at?->getTimestamp() ?? now()->getTimestamp()));
            }
            Scheduler::syncInvoice($fresh);
        });
    }

    private function receipt(Receipt $r): void
    {
        if ($r->wasChanged('status') && $r->status === 'approved') {
            self::later(fn () => Scheduler::schedule('receipt.approved', 'receipt', (int) $r->id, Scheduler::soon('receipt.approved'),
                (string) ($r->approved_at?->getTimestamp() ?? now()->getTimestamp())));
        } elseif ($r->wasChanged('status')) {
            self::later(fn () => Outbox::cancelFor('receipt', (int) $r->id, 'السند لم يعد معتمداً'));
        }
    }

    private function quote(QuoteRequest $q): void
    {
        if ($q->wasChanged(['status', 'quoted_at']) && $q->status === 'priced' && $q->quoted_at) {
            $date = Carbon::parse($q->quoted_at)->setTimezone('Asia/Amman')->toDateString();
            self::later(function () use ($q, $date) {
                foreach (Scheduler::occurrences('quote.followup', $date) as $n => $at) {
                    Scheduler::schedule('quote.followup', 'quote', (int) $q->id, $at, "{$date}:{$n}", ['date' => $date]);
                }
            });
        } elseif ($q->wasChanged('status') && $q->status !== 'priced') {
            self::later(fn () => Outbox::cancelFor('quote', (int) $q->id, 'تغيّرت حالة الطلب'));
        }
    }

    private static function later(\Closure $fn): void
    {
        DB::afterCommit(function () use ($fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                WaLog::error('wa.system.schedule_failed', ['exception' => $e]);
            }
        });
    }
}
