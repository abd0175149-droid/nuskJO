<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\MetaAnalytics;
use App\Support\FeatureFlags;
use Illuminate\Console\Command;

/** المطابقة اليومية الاختيارية مع تحليلات ميتا (E24): يعمل بالعلم meta_analytics_reconcile وحده، ونداء GET واحد. */
class WhatsAppMetaAnalytics extends Command
{
    protected $signature = 'whatsapp:meta-analytics';

    protected $description = 'قراءة يومية لتحليلات ميتا (أُرسل وسُلّم أمس) للمقارنة مع عدّنا فقط';

    public function handle(): int
    {
        if (!FeatureFlags::on('meta_analytics_reconcile')) {
            $this->line('المطابقة مطفأة (meta_analytics_reconcile).');

            return self::SUCCESS;
        }
        $r = MetaAnalytics::refresh();
        $r['ok']
            ? $this->info("ميتا: أُرسل {$r['meta_sent']} وسُلّم {$r['meta_delivered']} يوم {$r['day']} · عدّنا {$r['ours']}")
            : $this->warn('تعذّرت القراءة: '.$r['reason']);

        return self::SUCCESS;
    }
}
