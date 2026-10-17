<?php

namespace App\Console\Commands;

use App\Models\WaQrLink;
use App\Services\Ops\OpsAlerts;
use App\Services\WhatsApp\OfferQrService;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\FeatureFlags;
use Illuminate\Console\Command;

/**
 * مطابقة رموز QR عند ميتا بجدولنا (E38)، أسبوعياً وللقراءة فقط: العدد، والرموز غير المعروفة عندنا، والفعّالة عندنا
 * وغير الموجودة عند ميتا. لا يحذف شيئاً آلياً، وينبّه المدراء إن تجاوز العدد 1800 (الحد 2000 للرقم).
 */
class WhatsAppQrSync extends Command
{
    protected $signature = 'whatsapp:qr-sync {--json : مخرجات JSON}';

    protected $description = 'مقارنة رموز QR عند ميتا بالجدول المحلي (قراءة فقط)';

    public function handle(OpsAlerts $alerts): int
    {
        if (!FeatureFlags::on('wa_qr_links') || !OfferQrService::available()) {
            $this->line('العلم wa_qr_links مطفأ أو الهجرة لم تُشغَّل: لا مطابقة');

            return self::SUCCESS;
        }

        $r = WhatsAppChannel::listQrdls();
        if (!$r['ok']) {
            $this->error('تعذّرت القراءة من ميتا: '.$r['error']);

            return self::FAILURE;
        }

        $remote = collect($r['data'])->pluck('code')->all();
        $local = WaQrLink::counted()->whereNotNull('code')->pluck('code')->all();
        $out = [
            'meta' => count($remote),
            'local' => count($local),
            'unknown_at_meta' => count(array_diff($remote, $local)),
            'missing_at_meta' => count(array_diff($local, $remote)),
            'truncated' => (bool) ($r['truncated'] ?? false),
        ];

        if (max($out['meta'], $out['local']) > WaQrLink::WARN_AT) {
            $alerts->notifyAdmins('wa:qr-sync', '🔗 اقتربنا من حد رموز QR',
                'عند ميتا '.$out['meta'].' رمزاً ورابطاً من 2000 للرقم. احذف ما لا يُستعمل من تبويب «المشاركة» في العروض.', '/offers');
        }

        if ($this->option('json')) {
            $this->line(json_encode($out, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->table(['عند ميتا', 'عندنا', 'غير معروفة عندنا', 'غير موجودة عند ميتا'],
            [[$out['meta'], $out['local'], $out['unknown_at_meta'], $out['missing_at_meta']]]);
        if ($out['truncated']) {
            $this->warn('القائمة أطول من سقف الصفحات: النتيجة جزئية');
        }

        return self::SUCCESS;
    }
}
