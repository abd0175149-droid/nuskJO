<?php

namespace App\Console\Commands;

use App\Models\WaErasureRequest;
use App\Services\WhatsApp\ErasureRequests;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** تنبيه يومي بطلبات حذف تقترب من مهلة الثلاثين يوماً (E13.4): مرة لكل طلب في اليوم، بلا رقم ولا اسم */
class WhatsAppErasureSla extends Command
{
    protected $signature = 'whatsapp:erasure-sla';

    protected $description = 'تنبيه المدراء بطلبات الحذف المتأخرة';

    public function handle(): int
    {
        if (!ErasureRequests::available()) {
            return self::SUCCESS;
        }
        $late = WaErasureRequest::whereIn('status', WaErasureRequest::OPEN)
            ->where('received_at', '<=', now()->subDays((int) config('retention.erasure_warn_days', 20)))->orderBy('due_at')->get();

        foreach ($late as $r) {
            if (!Cache::add("wa:erasure:sla:{$r->id}:".now()->format('Ymd'), 1, now()->addDay())) {
                continue;
            }
            $left = (int) now()->startOfDay()->diffInDays($r->due_at?->startOfDay() ?? now(), false);
            WhatsAppInbox::notifyAdmins('⏰ طلب حذف يقترب من مهلته',
                "طلب #{$r->id} ({$r->status}) — ".($left >= 0 ? "بقي {$left} يوماً" : 'تجاوز المهلة بـ '.abs($left).' يوماً'), '/whatsapp/erasure-requests');
        }
        $this->line('طلبات متأخرة: '.$late->count());

        return self::SUCCESS;
    }
}
