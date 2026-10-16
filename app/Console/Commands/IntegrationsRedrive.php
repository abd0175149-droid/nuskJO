<?php

namespace App\Console\Commands;

use App\Jobs\DeliverIntegrationWebhook;
use App\Models\IntegrationEndpoint;
use App\Models\IntegrationOutboxRow;
use App\Services\Integrations\IntegrationOutbox;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * مُصالِح الصندوق الصادر (E40.6، كل 5 دقائق، بالعلم integration_outbox):
 *  1. «قيد التسليم» أقدم من 10 دقائق (عامل قُتل أثناء الإرسال) يعود معلّقاً. المستقبل يمنع التكرار بـ webhook-id.
 *  2. المعلّق الذي حلّ موعده بلا وظيفة حية يُجدول، مرة واحدة ولو شُغّل الأمر مرتين (dispatched_at ذرياً)، 100 صف حداً.
 *     وإن كانت وظائف التكاملات تنتظر في jobs بلا عامل يلتقطها، لا يضيف فوقها: ينبّه بأن العامل متوقف.
 *  3. تنبيه مرة في اليوم: صفوف dead خلال 24 ساعة، أو معلّق فوق 500.
 */
class IntegrationsRedrive extends Command
{
    protected $signature = 'integrations:redrive';

    protected $description = 'يعيد جدولة تسليمات التكاملات العالقة وينبّه على الفاشل والمتراكم';

    public function handle(): int
    {
        if (!IntegrationOutbox::enabled()) {
            $this->line('العلم integration_outbox مطفأ: لا شيء');

            return self::SUCCESS;
        }
        $stale = now()->subMinutes((int) config('integrations.stale_minutes', 10));

        $reclaimed = IntegrationOutboxRow::query()->where('status', IntegrationOutboxRow::DELIVERING)->where('updated_at', '<', $stale)
            ->update(['status' => IntegrationOutboxRow::PENDING, 'next_attempt_at' => now(), 'dispatched_at' => null, 'updated_at' => now()]);

        $queue = (string) config('integrations.queue', 'integrations');
        $workerStuck = DB::table('jobs')->where('queue', $queue)->whereNull('reserved_at')
            ->where('available_at', '<=', $stale->getTimestamp())->exists();

        $scheduled = 0;
        if ($workerStuck) {
            DeliverIntegrationWebhook::alertDaily('worker', '⛔ عامل التكاملات متوقف', 'تسليمات تنتظر في الطابور ولم يلتقطها عامل laravel-integrations. راجع supervisorctl status.');
        } else {
            $active = IntegrationEndpoint::active()->pluck('id');
            $due = fn (Builder $q) => $q->where('status', IntegrationOutboxRow::PENDING)
                ->where('next_attempt_at', '<=', now())
                ->whereIn('endpoint_id', $active)
                ->where(fn (Builder $w) => $w->whereNull('dispatched_at')
                    ->orWhereColumn('dispatched_at', '<', 'next_attempt_at')
                    ->orWhere('dispatched_at', '<=', $stale));

            $ids = IntegrationOutboxRow::query()->where($due)->orderBy('next_attempt_at')->limit((int) config('integrations.redrive_batch', 100))->pluck('id');
            foreach ($ids as $id) {
                // ذري: تشغيلان متزامنان للأمر لا يجدولان الصف مرتين
                if (IntegrationOutboxRow::query()->whereKey($id)->where($due)->update(['dispatched_at' => now()]) === 1) {
                    DeliverIntegrationWebhook::dispatchFor((int) $id);
                    $scheduled++;
                }
            }
        }

        $dead = IntegrationOutboxRow::query()->where('status', IntegrationOutboxRow::DEAD)->where('updated_at', '>=', now()->subDay())->count();
        if ($dead > 0) {
            DeliverIntegrationWebhook::alertDaily('dead-summary', '⚠️ تسليمات تكامل فاشلة', "{$dead} تسليماً فشل نهائياً خلال 24 ساعة. راجعها وأعدها من صفحة التكاملات.");
        }
        $pending = IntegrationOutboxRow::query()->where('status', IntegrationOutboxRow::PENDING)->count();
        if ($pending > (int) config('integrations.pending_alert', 500)) {
            DeliverIntegrationWebhook::alertDaily('backlog', '⏳ تسليمات تكامل متراكمة', "{$pending} تسليماً معلّقاً. هل المستقبل متوقف؟");
        }

        if ($reclaimed || $scheduled || $workerStuck) {
            Log::info('integrations.redrive', ['reclaimed' => $reclaimed, 'scheduled' => $scheduled, 'worker_stuck' => $workerStuck]);
        }
        $this->info("عاد معلّقاً {$reclaimed} · جُدول {$scheduled} · معلّق {$pending} · فاشل خلال 24 ساعة {$dead}".($workerStuck ? ' · ⛔ العامل لا يلتقط الطابور' : ''));

        return self::SUCCESS;
    }
}
