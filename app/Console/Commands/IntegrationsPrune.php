<?php

namespace App\Console\Commands;

use App\Models\IntegrationOutboxRow;
use App\Support\AuditActor;
use Illuminate\Console\Command;

/**
 * تقليم سجل التسليمات (E40.6، يومياً): المُسلَّم بعد 30 يوماً والميت بعد 90 (قرار المالك). الحمولة بيضاء أصلاً، فالحذف
 * تقليص لا حماية، ولذلك يعمل والعلم مطفأ أيضاً (ما بقي بعد الإطفاء لا يكبر). التعريف في IntegrationOutboxRow::prunable،
 * وهو نفسه في قائمة model:prune (E13)، وأثره صف retention.pruned بعدد الصفوف.
 */
class IntegrationsPrune extends Command
{
    protected $signature = 'integrations:prune';

    protected $description = 'يحذف التسليمات المُسلَّمة بعد 30 يوماً والفاشلة بعد 90';

    public function handle(): int
    {
        $count = AuditActor::as('system', 'artisan:integrations:prune', fn () => (new IntegrationOutboxRow)->pruneAll());
        $this->info("حُذف {$count} تسليماً");

        return self::SUCCESS;
    }
}
