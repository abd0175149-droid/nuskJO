<?php

namespace App\Console\Commands;

use App\Services\Ops\OpsAlerts;
use App\Services\WhatsApp\BlockSync;
use App\Support\AuditActor;
use App\Support\FeatureFlags;
use Illuminate\Console\Command;

/**
 * المزامنة القرائية اليومية مع قائمة الحظر عند ميتا (E31): GET وحده، ولا تغيّر أي حظر عند ميتا ولا عندنا إلا حالة
 * المطابقة (meta_state). الأعداد لبطاقة صفحة «المحظورون»، وإشعار المدراء إن زاد ما لم تجده ميتا أو ما عندها بلا سجل.
 * يعمل بالعلم wa_block وحده.
 */
class WhatsAppBlocksCheck extends Command
{
    protected $signature = 'whatsapp:blocks-check {--json : مخرجات JSON}';

    protected $description = 'مقارنة قائمة المحظورين عندنا بقائمة ميتا (قراءة فقط)';

    public function handle(OpsAlerts $alerts): int
    {
        if (!FeatureFlags::on('wa_block')) {
            $this->line('العلم wa_block مطفأ: لا مزامنة');

            return self::SUCCESS;
        }

        $before = BlockSync::lastSummary();
        $r = AuditActor::as('system', 'artisan:whatsapp:blocks-check', fn () => BlockSync::run('schedule'));
        if (!$r['ok']) {
            $this->error('تعذّرت المزامنة: '.$r['error']);

            return self::FAILURE;
        }

        $grew = $r['not_found'] !== [] && count($r['not_found']) > (int) ($before['not_found'] ?? 0)
            || $r['untracked'] !== [] && count($r['untracked']) > (int) ($before['untracked'] ?? 0);
        if ($grew) {
            $alerts->notifyAdmins('wa:blocks-check', '⛔ فروق في قائمة الحظر',
                count($r['not_found']).' حظر لم تجده ميتا، و'.count($r['untracked']).' محظور عند ميتا بلا سجل عندنا. راجع صفحة المحظورين.', '/whatsapp/blocks');
        }

        $out = array_intersect_key($r, array_flip(['meta', 'local', 'truncated'])) + [
            'not_found' => count($r['not_found']), 'untracked' => count($r['untracked']), 'mismatch' => count($r['mismatch']),
        ];
        if ($this->option('json')) {
            $this->line(json_encode($out, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->table(['عند ميتا', 'عندنا', 'لم تجده ميتا', 'بلا سجل عندنا', 'وصلت منه رسالة'], [[$out['meta'], $out['local'], $out['not_found'], $out['untracked'], $out['mismatch']]]);
        if ($out['truncated']) {
            $this->warn('القائمة أطول من سقف الصفحات: النتيجة جزئية');
        }

        return self::SUCCESS;
    }
}
