<?php

namespace App\Jobs;

use App\Services\Ops\OpsHealth;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * نبضة عامل: المجدول يدفعها كل دقيقة لكل طابور، والعامل الذي يخدمه يكتبها. عمرها في /up يكشف عاملاً ميتاً.
 * فريدة لكل طابور 10 دقائق: عامل ميت لا تتراكم له 1440 وظيفة في اليوم (على الأكثر 6 في الساعة).
 */
class OpsHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public int $uniqueFor = 600;

    public function __construct(public string $beatQueue)
    {
        $this->onQueue($beatQueue);
    }

    public function uniqueId(): string
    {
        return $this->beatQueue;
    }

    public function handle(): void
    {
        Cache::put(OpsHealth::beatKey($this->beatQueue), now()->getTimestamp());
    }
}
