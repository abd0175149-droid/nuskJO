<?php

namespace App\Console\Commands;

use App\Services\Ops\OpsMetrics;
use App\Services\Ops\OpsRules;
use Illuminate\Console\Command;

/**
 * فحص التشغيل كل 5 دقائق (E24): لقطة المقاييس (OpsMetrics) تُحفظ في مخزن ops (file)، ثم قواعد التنبيه (OpsRules)
 * من config('ops.rules'). الإشعار للمدراء في الجرس وFCM مباشرة بالعلم ops_alerts، وإلا سطر في perf.log فقط.
 * لا يرسل أي رسالة واتساب (القناة قد تكون المعطّلة)، ولا يكتب في القاعدة، ويخرج بالرمز 0 دائماً.
 *
 * الحد المعروف: إن مات المجدول لا يعمل هذا الأمر أصلاً. يكشف ذلك /up (E08) وفحص Docker والمراقب الخارجي على /up
 * (docs/RELEASE.md)، وهو قناة التنبيه الوحيدة لهذه الحالة.
 */
class WhatsAppOpsCheck extends Command
{
    protected $signature = 'whatsapp:ops-check {--json : طباعة اللقطة ونتيجة القواعد JSON}';

    protected $description = 'لقطة المقاييس التشغيلية وقواعد التنبيه (قراءة مجمّعة فقط، بلا رسائل واتساب)';

    public function handle(OpsMetrics $metrics, OpsRules $rules): int
    {
        try {
            $snap = $metrics->snapshot();
            OpsMetrics::remember($snap);
            $result = $rules->apply($snap);
            $evaluated = $result['rules'];
        } catch (\Throwable $e) {
            report($e);
            $this->warn('تعذّر الفحص: '.class_basename($e));

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['snapshot' => $snap, 'rules' => $evaluated, 'result' => array_diff_key($result, ['rules' => 1])], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(['القاعدة', 'القيمة', 'العتبة', 'الحالة'], collect($evaluated)->map(fn ($r, $rule) => [
            $rule, $r['value'] ?? '—', $r['threshold'], $r['skipped'] ? 'متخطاة ('.$r['skipped'].')' : ($r['firing'] ? '🔴 قائمة' : '🟢'),
        ])->values()->all());
        $this->line('الوضع: '.($result['mode'] === 'alert' ? 'تنبيه (ops_alerts)' : 'تسجيل فقط (ops_alerts مطفأ)')
            .' · أُرسل: '.(implode(', ', $result['fired']) ?: '—').' · عاد طبيعياً: '.(implode(', ', $result['resolved']) ?: '—')
            .($snap['errors'] ? ' · تعذّر: '.implode(', ', $snap['errors']) : ''));

        return self::SUCCESS;
    }
}
