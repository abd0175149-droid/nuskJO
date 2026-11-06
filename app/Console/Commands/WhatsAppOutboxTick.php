<?php

namespace App\Console\Commands;

use App\Jobs\DispatchOutboxItem;
use App\Models\WaOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * wa:outbox-tick كل دقيقة: يحجز المستحق من wa_outbox بعقد lease دقيقتين (المعاملة IMMEDIATE في SQLite تسلسل الحاجزين)
 * ويوزعه على وظائف wa-system بترتيب الأولوية ثم الموعد. الصف العالق في sending بعد انتهاء عقده يعود deferred.
 * صفوف «بموافقة» (awaiting_approval) لا تُحجز حتى يعتمدها موظف من صفحة الطابور.
 */
class WhatsAppOutboxTick extends Command
{
    protected $signature = 'wa:outbox-tick {--limit=30}';

    protected $description = 'إرسال المستحق من طابور رسائل النظام بحدود كل رقم ونوع';

    public function handle(): int
    {
        $stale = WaOutbox::where('status', WaOutbox::SENDING)->where('lease_until', '<', now())
            ->update(['status' => WaOutbox::DEFERRED, 'lease_until' => null, 'defer_reason' => 'انقطع الإرسال، يُعاد', 'updated_at' => now()]);

        $ids = DB::transaction(function () {
            $ids = WaOutbox::query()->claimable()->orderByDesc('priority')->orderBy('due_at')->orderBy('id')
                ->limit(max(1, (int) $this->option('limit')))->pluck('id')->all();
            if ($ids !== []) {
                WaOutbox::whereIn('id', $ids)->claimable()->update([
                    'status' => WaOutbox::SENDING, 'lease_until' => now()->addMinutes(2), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
                ]);
            }

            return WaOutbox::whereIn('id', $ids)->where('status', WaOutbox::SENDING)->pluck('id')->all();
        });
        foreach ($ids as $id) {
            DispatchOutboxItem::dispatch((int) $id);
        }
        $this->line('claimed '.count($ids).($stale ? ", stale {$stale}" : ''));

        return self::SUCCESS;
    }
}
