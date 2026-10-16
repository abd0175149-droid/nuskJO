<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\MetaHealth;
use App\Services\WhatsApp\WaLog;
use Illuminate\Console\Command;

/**
 * المراقب الدوري لقناة ميتا (E23): الاسم المعتمد whatsapp:health، والاسم القديم whatsapp:health-status (E08) بديل له.
 *
 * - العلم channel_monitor مطفأ: فحص health_status كما كان (E08) مرة في الساعة (الجدولة تتخطى نصف الساعة).
 * - مفعّل: عقدة الرقم كل 30 دقيقة (الصحة والجودة والحالة وwebhook_configuration) إلى آلة الحالات (E22)، والاشتراكات
 *   كل ساعة أو مع --force، وتنبيه الانحراف مرة لكل تغيّر. الرمز من فحص E08 اليومي، فلا debug_token هنا.
 *
 * قراءة GET فقط، ولا رسالة واتساب، ويعيد 0 دائماً حتى لا يمتلئ سجل المجدول بالفشل.
 */
class WhatsAppHealth extends Command
{
    protected $signature = 'whatsapp:health {--force : اقرأ الاشتراكات الآن مهما كان عمر آخر قراءة}';

    protected $aliases = ['whatsapp:health-status'];

    protected $description = 'المراقب الدوري: صحة الرقم عند ميتا وجودته، واشتراكات الويبهوك وانحرافها، وحالة الرمز (قراءة فقط)';

    public function handle(MetaHealth $health): int
    {
        if (!config('ops.meta_checks')) {
            $this->line('فحوص ميتا مطفأة (WA_META_CHECKS=false)');

            return self::SUCCESS;
        }

        try {
            if (!MetaHealth::monitorOn()) {
                $r = $health->channel();
                $this->line('صحة القناة: '.$this->state($r));

                return self::SUCCESS;
            }

            $r = $health->monitor((bool) $this->option('force'));
            $this->line('صحة القناة: '.$this->state($r['channel']));
            if (isset($r['channel']['number'])) {
                $n = $r['channel']['number'];
                $this->line('الرقم: '.($n['status'] ?? '؟').' · الاسم '.($n['name_status'] ?? '؟').' · الإنتاجية '.($n['throughput'] ?? '؟')
                    .($n['messaging_limit'] ? ' · الحد '.$n['messaging_limit'] : ''));
            }
            $w = $r['webhook'];
            $this->line('الويبهوك: '.match ($w['ok'] ?? null) {
                true => 'سليم',
                false => 'انحراف ('.implode(', ', $w['issues']).')',
                default => 'غير معروف بعد',
            }.($r['subscriptions_checked'] ? ' · قُرئت الاشتراكات الآن' : ''));
            $c = (array) ($r['credentials'] ?? []);
            $this->line('الرمز (من الفحص اليومي): '.($c['state'] ?? 'لم يُفحص')
                .(isset($c['days_left']) ? " · بعد {$c['days_left']} يوماً" : (($c['never_expires'] ?? false) ? ' · لا ينتهي' : '')));
            $this->line('إصدار Graph: '.($r['graph']['version'] ?? '؟').' ('.$r['graph']['level'].')');
        } catch (\Throwable $e) {
            // لا فشل في سجل المجدول: السطر يحمل الصنف وحده (نص الاستثناء قد يضم رابطاً)
            WaLog::error('health.monitor_failed', ['type' => class_basename($e)]);
            $this->warn('تعذّر الفحص: '.class_basename($e));
        }

        return self::SUCCESS;
    }

    private function state(array $r): string
    {
        return ($r['state'] ?? '؟').' '.($r['can_send_message'] ?? '').(($r['reasons'] ?? []) ? ' ('.implode(', ', $r['reasons']).')' : '');
    }
}
