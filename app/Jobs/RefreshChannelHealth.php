<?php

namespace App\Jobs;

use App\Services\WhatsApp\AccountEventHandler;
use App\Services\WhatsApp\ChannelHealth;
use App\Services\WhatsApp\MetaHealth;
use App\Services\WhatsApp\WaLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * فحص health_status فوراً (E22): بعد حدث حساب ذي معنى، وعند مرور انتهاء القيد، وبعد حفظ رمز جديد (humanInitiated).
 * الفحص مرجع الحالة (ChannelHealth::fromPoll عبر MetaHealth::channel). فريد 5 دقائق: أحداث متتالية فحص واحد،
 * والفحص البشري منفصل عن الآلي فلا يبتلعه. الطابور default لا طابور الردود، ومحاولة واحدة.
 * يعيد أولاً أثر الأحداث التي فشل أثرها (handled_at فارغ).
 */
class RefreshChannelHealth implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public int $uniqueFor = 300;

    public function __construct(public bool $humanInitiated = false) {}

    public function uniqueId(): string
    {
        return $this->humanInitiated ? 'human' : 'auto';
    }

    public function handle(MetaHealth $health): void
    {
        if (!ChannelHealth::enabled()) {
            return;
        }
        AccountEventHandler::retryUnhandled();
        if (!config('ops.meta_checks')) {
            WaLog::info('health.poll_skipped', ['reason' => 'meta_checks_off']);

            return;
        }
        $health->channel($this->humanInitiated);
    }
}
