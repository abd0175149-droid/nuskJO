<?php

namespace App\Console\Commands;

use App\Services\Ops\OpsHealth;
use Illuminate\Console\Command;

/** حالة التشغيل كما يراها /up، مع أعمار النبضات (قراءة فقط، للتحقق بعد النشر عبر docker exec). */
class OpsStatus extends Command
{
    protected $signature = 'ops:status';

    protected $description = 'رموز أعطال /up وأعمار نبضات المجدول والعمّال وعدد الوظائف الفاشلة';

    public function handle(OpsHealth $health): int
    {
        $s = $health->snapshot();
        $age = fn (?int $seconds) => $seconds === null ? 'لا نبضة' : "{$seconds} ث";

        $rows = [['المجدول', $age($s['schedule_age'])]];
        foreach ($s['queues'] as $queue => $seconds) {
            $rows[] = ["العامل ({$queue})", $age($seconds)];
        }
        $rows[] = ['وظائف فاشلة (24 ساعة)', $s['failed_24h'] ?? '؟'];
        $this->table(['المكوّن', 'آخر نبضة'], $rows);

        $this->line('فحص /up: '.($s['enabled'] ? 'مفعّل' : 'مطفأ (OPS_HEALTH_CHECKS=false)'));
        // E24: الإصدار المنشور، وعمر آخر لقطة من whatsapp:ops-check
        $build = (array) config('app.build');
        $this->line('الإصدار: '.($build['sha'] ?? 'unknown').(!empty($build['time']) ? ' — بُني '.$build['time'] : ''));
        $snap = \App\Services\Ops\OpsMetrics::last();
        $this->line('آخر لقطة تشغيل: '.($snap ? 'قبل '.max(0, now()->getTimestamp() - (int) ($snap['at'] ?? 0)).' ث · صادر اليوم '.($snap['outbound_today']['total'] ?? 0)
            : 'لا لقطة (whatsapp:ops-check لم يعمل خلال 15 دقيقة)'));
        $s['codes'] === [] ? $this->info('✅ لا أعطال') : $this->error('❌ '.implode(', ', $s['codes']));

        return self::SUCCESS;
    }
}
