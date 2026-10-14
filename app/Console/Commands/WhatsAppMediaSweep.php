<?php

namespace App\Console\Commands;

use App\Models\WaMessage;
use App\Services\WhatsApp\Media\MediaArchiver;
use App\Services\WhatsApp\WaLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * كنس ساعي لأرشيف المرفقات (E14.9): المؤقت الأقدم من ساعة، والملف اليتيم (لا رسالة تشير إليه) الأقدم من ساعة،
 * والرسالة المحفوظة بلا ملف تصير missing، ثم السقف: تنبيه فوق 80% وإيقاف الأرشفة الجديدة عند 100%.
 */
class WhatsAppMediaSweep extends Command
{
    protected $signature = 'whatsapp:media-sweep';

    protected $description = 'كنس الملفات المؤقتة واليتيمة وفحص سقف أرشيف المرفقات';

    private const GRACE_SECONDS = 3600;

    public function handle(): int
    {
        $tmp = $this->sweepTmp();
        $orphans = $this->sweepOrphans();
        $missing = $this->markMissing();
        $quota = $this->checkQuota();

        WaLog::info('media.sweep', ['count' => $tmp + $orphans + $missing, 'reason' => "tmp={$tmp} orphans={$orphans} missing={$missing} quota={$quota}%"]);
        $this->line("مؤقت: {$tmp} · يتيم: {$orphans} · مفقود: {$missing} · السقف: {$quota}%");

        return self::SUCCESS;
    }

    private function sweepTmp(): int
    {
        $n = 0;
        foreach (glob(MediaArchiver::tmpDir().'/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - self::GRACE_SECONDS && @unlink($file)) {
                $n++;
            }
        }

        return $n;
    }

    /** لكل مجلد شهر: ملفات القرص مقابل media_path على دفعات */
    private function sweepOrphans(): int
    {
        $disk = MediaArchiver::disk();
        $n = 0;
        foreach ($disk->directories() as $year) {
            if (!preg_match('/^\d{4}$/', $year)) {
                continue;
            }
            foreach ($disk->directories($year) as $month) {
                foreach (array_chunk($disk->files($month), 500) as $files) {
                    $known = WaMessage::whereIn('media_path', $files)->pluck('media_path')->flip();
                    foreach ($files as $file) {
                        if (!$known->has($file) && $disk->lastModified($file) < time() - self::GRACE_SECONDS) {
                            $disk->delete($file);
                            $n++;
                        }
                    }
                }
            }
        }

        return $n;
    }

    private function markMissing(): int
    {
        $disk = MediaArchiver::disk();
        $n = 0;
        WaMessage::where('media_status', 'stored')->select(['id', 'media_path'])->chunkById(500, function ($rows) use ($disk, &$n) {
            foreach ($rows as $m) {
                if (!$m->media_path || !$disk->exists($m->media_path)) {
                    WaMessage::whereKey($m->id)->update(['media_status' => 'missing']);
                    $n++;
                }
            }
        });
        if ($n > 0) {
            MediaArchiver::alertOnce('missing', '⚠️ مرفقات مفقودة من الأرشيف', "{$n} مرفقاً محفوظاً لم يعد ملفه على الخادم. راجع النسخ أو الاستعادة الأخيرة.");
        }

        return $n;
    }

    /** النسبة المستعملة من السقف. العلامة تُرفع تحت 100% فتعود الأرشفة وحدها */
    private function checkQuota(): int
    {
        $quota = max(1, (int) config('whatsapp.media.quota_mb')) * 1048576;
        $used = MediaArchiver::usedBytes();
        $pct = (int) floor($used / $quota * 100);
        $usedMb = (int) round($used / 1048576);
        $quotaMb = (int) ($quota / 1048576);

        if ($pct >= 100) {
            Cache::put('wa:media:quota_exceeded', true, now()->addDay());
            MediaArchiver::alertOnce('quota', '⚠️ أرشيف المرفقات بلغ سقفه', "{$usedMb} من {$quotaMb} MB. توقفت أرشفة المرفقات الجديدة حتى يُرفع السقف (WA_MEDIA_QUOTA_MB) أو تُقلَّم القديمة.");
        } else {
            Cache::forget('wa:media:quota_exceeded');
            if ($pct >= (int) config('whatsapp.media.quota_warn_pct', 80)) {
                MediaArchiver::alertOnce('quota-warn', '⚠️ أرشيف المرفقات يقترب من سقفه', "{$usedMb} من {$quotaMb} MB ({$pct}%).");
            }
        }

        return $pct;
    }
}
