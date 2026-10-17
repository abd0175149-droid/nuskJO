<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * أوامر الدقيقة في عملية واحدة (E24، ميزانية الحمل، العلم ops_tick): إعادة الويبهوك (E20) ثم المُصالِح (E07) بالترتيب،
 * كلٌّ في try/catch فلا يوقف أحدهما الآخر. يوفّر إقلاع PHP كاملاً كل دقيقة على الخادم المشترك. مطفأ = كل أمر في عمليته.
 * القائمة الموحدة وميزانية الحمل في docs/ops/load-budget.md.
 */
class OpsTick extends Command
{
    /** بالترتيب: لا يعتمد أحدها على الآخر، وكلها محدودة بميزانيتها (--limit و5 دقائق) */
    public const COMMANDS = ['whatsapp:replay-webhooks', 'whatsapp:reconcile-replies'];

    protected $signature = 'ops:tick';

    protected $description = 'أوامر الدقيقة (إعادة الويبهوك والمُصالِح) في عملية واحدة';

    public function handle(): int
    {
        foreach (self::COMMANDS as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable $e) {
                report($e);
                $this->warn("{$command}: ".class_basename($e));
            }
        }

        return self::SUCCESS;
    }
}
