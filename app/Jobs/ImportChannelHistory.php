<?php

namespace App\Jobs;

use App\Services\WhatsApp\OpenWa\HistoryImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * حلقة من سلسلة استيراد السجل (OpenWa\HistoryImport): خطوات حتى ميزانية ثوانٍ، ثم الحلقة التالية بعد ثانيتين.
 * طابور wa-import آخر عامل laravel-media: لا يؤخر ملفات الموظف ولا رسائل النظام ولا الأرشفة.
 * المهلة 120 < --timeout 125 للعامل: خطوة واحدة = نداء بوابة (مهلة import 90) + كتابة المحادثة.
 * $run يربط الحلقة بتشغيلها: إيقاف أو استئناف جديد يبطل كل حلقة منتظرة من التشغيل السابق.
 */
class ImportChannelHistory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $channelId, public string $run)
    {
        $this->onQueue('wa-import');
    }

    public function handle(): void
    {
        // حلقتان للرقم نفسه لا تعملان معاً (استئناف يدوي فوق حلقة ما زالت تعمل)
        $lock = Cache::lock('wa:import:'.$this->channelId, 150);
        if (!$lock->get()) {
            return;
        }
        try {
            $more = HistoryImport::step($this->channelId, $this->run);
        } finally {
            $lock->release();
        }
        if ($more) {
            self::dispatch($this->channelId, $this->run)->delay(now()->addSeconds(2));
        }
    }

    public function failed(\Throwable $e): void
    {
        HistoryImport::fail($this->channelId, $this->run, class_basename($e));
    }
}
