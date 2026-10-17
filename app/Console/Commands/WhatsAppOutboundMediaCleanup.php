<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaMessage;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\AuditActor;
use App\Support\FeatureFlags;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * حذف الملفات المرسلة من خوادم ميتا بعد تسليمها (E32.12، كل ساعة، خلف العلم outbound_media_remote_delete المطفأ).
 * ميتا تحفظ الملف المرفوع 30 يوماً؛ بعد الحذف لا يستطيع العميل تنزيله من جديد إن لم يكن نزّله، فالأنواع والحالات بقرار
 * المالك (الافتراضي: المستندات بعد «قُرئت» وحدها). لا يُحذف ما بقي «أُرسلت» (هاتف العميل قد يكون مطفأً)، ولا بطاقات
 * البوت (يُعاد إرسالها بالمعرّف المخزّن). نسختنا الخاصة على wa_media لا تُمس. دفعة واحدة في التشغيل.
 */
class WhatsAppOutboundMediaCleanup extends Command
{
    protected $signature = 'whatsapp:outbound-media-cleanup {--dry-run : يعدّ ولا يحذف}';

    protected $description = 'حذف الملفات المرسلة من خوادم واتساب بعد تسليمها (بقرار المالك)';

    /** الحالات التي تثبت التسليم (رتب E07/E21) */
    private const DELIVERED = ['delivered', 'read', 'played'];

    /** ما بعد 30 يوماً انتهى عند ميتا أصلاً */
    private const META_DAYS = 30;

    public function handle(): int
    {
        if (!FeatureFlags::on('outbound_media_remote_delete')) {
            $this->line('العلم outbound_media_remote_delete مطفأ: لا شيء.');

            return self::SUCCESS;
        }
        if (!Schema::hasColumn('wa_messages', 'media_remote_deleted_at')) {
            $this->line('الهجرة 2026_10_14_000032 لم تُشغَّل بعد: لا شيء.');

            return self::SUCCESS;
        }

        // audio: رسالة الموظف الصوتية (E34)، بقرار المالك كبقية الأنواع (بعد played مثلاً)
        $types = array_values(array_intersect((array) config('whatsapp.outbound.remote_delete.types', []), ['image', 'document', 'video', 'audio']));
        $statuses = array_values(array_intersect((array) config('whatsapp.outbound.remote_delete.statuses', []), self::DELIVERED));
        if ($types === [] || $statuses === []) {
            $this->line('لا أنواع أو حالات مضبوطة للحذف: لا شيء.');

            return self::SUCCESS;
        }

        $query = WaMessage::where('direction', 'out')
            ->whereNotNull('media_id')->where('media_id', '!=', '')
            ->whereNull('media_remote_deleted_at')
            ->whereIn('msg_type', $types)
            ->whereIn('status', $statuses)
            ->where('updated_at', '<', now()->subMinutes((int) config('whatsapp.outbound.remote_delete.min_age_minutes', 60)))
            ->where('created_at', '>', now()->subDays(self::META_DAYS))
            // بطاقات العروض من البوت: معرّفها مخزّن ويُعاد إرساله لعملاء آخرين
            ->where(fn ($q) => $q->where('source', '!=', 'bot')->orWhere('msg_type', '!=', 'image'));

        if ($this->option('dry-run')) {
            $this->line('[تجربة] سيُحذف من خوادم واتساب: '.(clone $query)->count().' ملفاً.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $failed = 0;
        foreach ($query->orderBy('id')->limit(max(1, (int) config('whatsapp.outbound.remote_delete.batch', 50)))->get(['id', 'media_id']) as $m) {
            $ok = WhatsAppChannel::deleteMedia((string) $m->media_id);
            if ($ok === null) {
                $this->line('القناة غير مهيّأة: توقف.');
                break;
            }
            if ($ok) {
                WaMessage::whereKey($m->id)->whereNull('media_remote_deleted_at')->update(['media_remote_deleted_at' => now()]);
                $deleted++;
            } else {
                $failed++;
            }
        }

        if ($deleted > 0 || $failed > 0) {
            WaLog::info('wa.media.remote_deleted', ['count' => $deleted, 'failed' => $failed]);
        }
        if ($deleted > 0) {
            AuditActor::as('system', 'artisan:whatsapp:outbound-media-cleanup', fn () => AuditLog::sampled(AuditAction::WaMediaRemoteDelete,
                'cleanup', 'retention', null, 'media', ['deleted' => $deleted, 'failed' => $failed]));
        }
        $this->line("حُذف من خوادم واتساب: {$deleted} · تعذّر: {$failed}");

        return self::SUCCESS;
    }
}
