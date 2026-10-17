<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\ReplyReconciler;
use App\Services\WhatsApp\ReplyState;
use Illuminate\Console\Command;

/** المُصالِح: يعيد جدولة الردود الضائعة أو يرفعها «تدخّل». مجدول كل دقيقة، ويعمل فقط والعلم reconcile مفعّل. */
class ReconcileWhatsAppReplies extends Command
{
    protected $signature = 'whatsapp:reconcile-replies {--dry-run : يطبع ما كان سيُفعل دون أي كتابة}';

    protected $description = 'إعادة جدولة ردود الواتساب الضائعة، أو رفعها للموظفين بسبب ظاهر';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = ReplyReconciler::run($dryRun);

        if ($result['queue_lag'] > 0) {
            $this->warn("🐢 {$result['queue_lag']} وظيفة رد تنتظر في الطابور أكثر من ".ReplyReconciler::QUEUE_LAG_MINUTES.' دقائق');
        }
        if ($result['skipped'] !== null) {
            $this->line("لم يعمل: {$result['skipped']}");

            return self::SUCCESS;
        }
        if ($result['actions'] === []) {
            $this->line('لا ردود ضائعة');

            return self::SUCCESS;
        }

        $this->table(['المحادثة', 'الرسالة', 'الإجراء'], array_map(fn ($a) => [
            $a['conv_id'],
            $a['message_id'],
            $a['action'] === 'scheduled' ? 'أُعيدت الجدولة' : 'تدخّل: '.(ReplyState::REASONS[$a['action']] ?? $a['action']),
        ], $result['actions']));
        if ($dryRun) {
            $this->line('(تجربة: لم يُكتب شيء)');
        }

        return self::SUCCESS;
    }
}
