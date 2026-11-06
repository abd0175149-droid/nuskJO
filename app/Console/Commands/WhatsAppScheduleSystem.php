<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\QuoteRequest;
use App\Services\WhatsApp\System\Scheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * wa:schedule-system كل 15 دقيقة: شبكة أمان للجدولة الحدثية (WhatsAppSystemObserver). الفواتير المعتمدة التي تقع
 * رحلتها أو استحقاقها أو عودتها في الأيام القادمة، وطلبات التسعير المسعّرة بلا رد، وملخص الإدارة اليومي.
 * آمن للتكرار: المفتاح send_key يمنع أي صف ثانٍ، وما تغيّر يُعاد ضبطه.
 */
class WhatsAppScheduleSystem extends Command
{
    protected $signature = 'wa:schedule-system {--days=5 : أفق المسح بالأيام}';

    protected $description = 'جدولة رسائل النظام المستحقة من الفواتير والطلبات (شبكة أمان للجدولة الحدثية)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $from = now()->subDays(2)->toDateString();
        $to = now()->addDays($days + 3)->toDateString();
        $n = 0;
        Invoice::query()->with('offer:id,return_date')->where('status', 'approved')
            ->where(fn ($q) => $q->whereBetween('trip_date', [$from, $to])->orWhereBetween('due_date', [$from, $to])
                ->orWhereHas('offer', fn ($o) => $o->whereBetween('return_date', [now()->subDays(2)->toDateString(), $to])))
            ->chunkById(200, function ($invoices) use (&$n) {
                foreach ($invoices as $inv) {
                    Scheduler::syncInvoice($inv);
                    $n++;
                }
            });

        $q = 0;
        QuoteRequest::query()->where('status', 'priced')->where('quoted_at', '>=', now()->subDays(4))->each(function (QuoteRequest $r) use (&$q) {
            $date = Carbon::parse($r->quoted_at)->setTimezone('Asia/Amman')->toDateString();
            foreach (Scheduler::occurrences('quote.followup', $date) as $i => $at) {
                if ($at->gt(now()->subHour())) {
                    Scheduler::schedule('quote.followup', 'quote', (int) $r->id, $at, "{$date}:{$i}", ['date' => $date]);
                    $q++;
                }
            }
        });

        $summary = Scheduler::adminSummary(now());
        $this->line("invoices {$n} · quotes {$q} · admin {$summary}");

        return self::SUCCESS;
    }
}
