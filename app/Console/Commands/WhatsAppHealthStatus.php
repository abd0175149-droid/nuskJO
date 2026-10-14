<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\MetaHealth;
use Illuminate\Console\Command;

/** صحة الإرسال من رقمنا عند ميتا (health_status) كل ساعة. يعيد 0 دائماً. */
class WhatsAppHealthStatus extends Command
{
    protected $signature = 'whatsapp:health-status';

    protected $description = 'هل تسمح ميتا بالإرسال من رقمنا (الرقم والحساب والنشاط والتطبيق)، وجودة الرقم';

    public function handle(MetaHealth $health): int
    {
        if (!config('ops.meta_checks')) {
            $this->line('فحوص ميتا مطفأة (WA_META_CHECKS=false)');

            return self::SUCCESS;
        }

        $r = $health->channel();
        $this->line('صحة القناة: '.$r['state'].' '.($r['can_send_message'] ?? '').($r['reasons'] ? ' ('.implode(', ', $r['reasons']).')' : ''));

        return self::SUCCESS;
    }
}
