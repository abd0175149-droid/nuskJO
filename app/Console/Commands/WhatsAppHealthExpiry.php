<?php

namespace App\Console\Commands;

use App\Enums\WaHealth;
use App\Jobs\RefreshChannelHealth;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\ChannelHealth;
use Illuminate\Console\Command;

/**
 * مرور انتهاء القيد (E22): إن مضى wa_health_until والحالة BLOCKED أو LIMITED، يُطلق فحص health_status.
 * إن أكّد الفحص: LIMITED تصير AVAILABLE آلياً، وBLOCKED تضع اقتراح رفع وتنبيهاً فقط. يعيد 0 دائماً.
 */
class WhatsAppHealthExpiry extends Command
{
    protected $signature = 'whatsapp:health-expiry';

    protected $description = 'فحص صحة القناة عند مرور تاريخ انتهاء قيد ميتا (لا رفع آلياً للحجب)';

    public function handle(): int
    {
        if (!ChannelHealth::enabled()) {
            return self::SUCCESS;
        }
        $s = WaBotSetting::current();
        $state = ChannelHealth::current($s);
        if (in_array($state, [WaHealth::Blocked, WaHealth::Limited], true) && $s->wa_health_until !== null && $s->wa_health_until->isPast()) {
            RefreshChannelHealth::dispatch();
            $this->line('انتهى تاريخ القيد ('.$state->value.') — أُطلق فحص من ميتا');
        }

        return self::SUCCESS;
    }
}
