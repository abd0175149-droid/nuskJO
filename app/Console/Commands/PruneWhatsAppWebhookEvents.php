<?php

namespace App\Console\Commands;

use App\Models\WaWebhookEvent;
use App\Services\Ops\OpsAlerts;
use App\Services\WhatsApp\WaLog;
use Illuminate\Console\Command;

/**
 * احتفاظ سجل الويبهوك (E20.8، كل ساعة): الجسم المشفّر يُمحى بعد purge_after (72 ساعة من الاستلام) ولأي صف حُسم
 * بقي فيه جسم، والأثر الوصفي (هاش وحالة وأعداد) يُحذف بعد 14 يوماً. وحارس خصوصية: جسم باقٍ أقدم من 72 ساعة
 * بعد التشغيل ينبّه المدراء ويظهر في whatsapp:doctor. كل ساعة لا يومياً: فلا يتجاوز الجسم 72 ساعة إلا بدقائق.
 */
class PruneWhatsAppWebhookEvents extends Command
{
    protected $signature = 'whatsapp:webhook-events-prune';

    protected $description = 'يمحو أجسام إشعارات الويبهوك بعد مدة الاحتفاظ ويحذف الأثر الوصفي القديم';

    public function handle(OpsAlerts $alerts): int
    {
        $wiped = WaWebhookEvent::whereNotNull('raw_body')
            ->where(fn ($q) => $q->where('purge_after', '<=', now())
                ->orWhereIn('status', [WaWebhookEvent::PROCESSED, WaWebhookEvent::REJECTED]))
            ->update(['raw_body' => null]);

        $deleted = WaWebhookEvent::where('received_at', '<', now()->subDays((int) config('whatsapp.webhook.meta_days', 14)))->delete();

        $hours = (int) config('whatsapp.webhook.body_hours', 72);
        $overdue = WaWebhookEvent::whereNotNull('raw_body')->where('received_at', '<', now()->subHours($hours))->count();
        if ($overdue > 0) {
            WaLog::error('webhook.retention_overdue', ['count' => $overdue]);
            $alerts->notifyAdmins('wa:webhook:retention', '⛔ أجسام إشعارات واتساب بعد مدة الاحتفاظ',
                "{$overdue} جسم محفوظ أقدم من {$hours} ساعة رغم المحو. راجع whatsapp:doctor فوراً.");
        }

        if ($wiped || $deleted || $overdue) {
            WaLog::info('webhook.prune', ['wiped' => $wiped, 'deleted' => $deleted, 'overdue' => $overdue]);
        }
        $this->info("مُحي {$wiped} جسماً · حُذف {$deleted} صفاً".($overdue ? " · ⛔ باقٍ بعد المدة: {$overdue}" : ''));

        return $overdue > 0 ? self::FAILURE : self::SUCCESS;
    }
}
