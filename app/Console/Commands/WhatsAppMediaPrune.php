<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaMessage;
use App\Services\WhatsApp\Media\MediaArchiver;
use App\Support\AuditActor;
use App\Support\Retention;
use Illuminate\Console\Command;

/**
 * الاحتفاظ بالمرفقات (E14.9): ما حُفظ قبل WA_MEDIA_RETENTION_DAYS (120 مقترحاً) يُحذف ملفه ويصير purged. يبقى النوع
 * والحجم (بلا بيان شخصي). لا تقليم بلا نسخة ليلية حديثة. --dry-run يعدّ فقط.
 */
class WhatsAppMediaPrune extends Command
{
    protected $signature = 'whatsapp:media-prune {--days= : المدة بالأيام (افتراضياً WA_MEDIA_RETENTION_DAYS)} {--dry-run}';

    protected $description = 'حذف مرفقات العملاء الأقدم من مدة الاحتفاظ';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('whatsapp.media.retention_days'));
        if ($days <= 0) {
            $this->line('معطّل: لا مدة احتفاظ للمرفقات.');

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry-run');
        if (!$dry && !Retention::backupFresh()) {
            $this->line('متوقف: لا نسخة احتياطية ناجحة خلال 26 ساعة.');

            return self::SUCCESS;
        }

        $query = WaMessage::where('media_status', 'stored')->where('media_archived_at', '<', now()->subDays($days));
        if ($dry) {
            $this->line(sprintf('سيُحذف %d ملفاً (%.1f MB).', (clone $query)->count(), (clone $query)->sum('media_size') / 1048576));

            return self::SUCCESS;
        }

        $files = 0;
        $bytes = 0;
        $disk = MediaArchiver::disk();
        $query->select(['id', 'media_path', 'media_size'])->chunkById(500, function ($rows) use ($disk, &$files, &$bytes) {
            foreach ($rows as $m) {
                if ($m->media_path) {
                    $disk->delete($m->media_path);
                }
                WaMessage::whereKey($m->id)->where('media_status', 'stored')->update(['media_status' => 'purged', 'media_path' => null]);
                $files++;
                $bytes += (int) $m->media_size;
            }
        });

        if ($files > 0) {
            AuditActor::as('system', 'artisan:whatsapp:media-prune', fn () => AuditLog::log(AuditAction::WaMediaPurge, 'retention', null, 'media',
                ['mode' => 'retention', 'days' => $days, 'files' => $files, 'bytes' => $bytes]));
        }
        $this->line(sprintf('حُذف %d ملفاً (%.1f MB).', $files, $bytes / 1048576));

        return self::SUCCESS;
    }
}
