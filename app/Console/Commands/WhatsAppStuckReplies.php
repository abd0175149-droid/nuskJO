<?php

namespace App\Console\Commands;

use App\Services\Ops\OpsAlerts;
use App\Services\WhatsApp\StuckReplies;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * تقرير الردود العالقة (E17.5) للقراءة: معرّفات ودقائق انتظار وتشخيص، بلا هاتف ولا نص.
 * مع --notify (المجدول كل 10 دقائق): تنبيه للإدارة مرة في الساعة إن بقيت محادثة عالقة 15 دقيقة.
 */
class WhatsAppStuckReplies extends Command
{
    protected $signature = 'whatsapp:stuck-replies {--minutes=3 : عمر أحدث وارد بلا رد} {--json : مخرجات JSON} {--notify : تنبيه الإدارة إن بقيت عالقة 15 دقيقة}';

    protected $description = 'المحادثات التي ينتظر فيها العميل رداً آلياً لم يصل';

    public function handle(OpsAlerts $alerts): int
    {
        $rows = StuckReplies::find(max(1, (int) $this->option('minutes')))
            ->map(fn ($c) => StuckReplies::diagnose($c))->values()->all();

        Cache::put(StuckReplies::CACHE_KEY, array_column($rows, 'conv_id'), now()->addMinutes(StuckReplies::CACHE_MINUTES));

        if ($this->option('notify')) {
            $long = count(array_filter($rows, fn ($r) => $r['waiting_min'] >= StuckReplies::ALERT_MINUTES));
            if ($long > 0) {
                $alerts->notifyAdmins('wa:stuck', '🧩 ردود عالقة', "{$long} محادثة بلا رد منذ أكثر من ".StuckReplies::ALERT_MINUTES.' دقيقة — افتح الصندوق', '/whatsapp/inbox?filter=stuck');
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode(['count' => count($rows), 'conversations' => $rows], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $rows
            ? $this->table(['المحادثة', 'دقائق الانتظار', 'التشخيص', 'محاولات المُصالِح'], array_map(fn ($r) => [$r['conv_id'], $r['waiting_min'], $r['state'], $r['attempts']], $rows))
            : $this->info('لا ردود عالقة');

        return self::SUCCESS;
    }
}
