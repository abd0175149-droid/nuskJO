<?php

namespace App\Jobs;

use App\Models\WaMessage;
use App\Services\WhatsApp\Media\MediaArchiver;
use App\Services\WhatsApp\WaLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Carbon;

/**
 * أرشفة مرفق وارد (E14.4) على طابور wa-media بعامل مستقل (laravel-media): التنزيل الطويل لا يؤخر رد البوت.
 * الحمولة معرّف الرسالة فقط (لا رابط ولا رقم). المحاولات حتى قبل انتهاء المعرّف عند ميتا (7 أيام).
 */
final class ArchiveWhatsAppMedia implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 8;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600, 10800, 21600, 43200];

    /** أقل من --timeout للعامل (125) ومن stopwaitsecs (130) ومن stop_grace_period في compose (140) */
    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(public int $messageId, public ?int $capOverride = null)
    {
        $this->onQueue(config('whatsapp.media.queue', 'wa-media'));
    }

    public function uniqueId(): string
    {
        return (string) $this->messageId;
    }

    public function retryUntil(): \DateTimeInterface
    {
        $at = WaMessage::whereKey($this->messageId)->value('created_at');

        return $at ? Carbon::parse($at)->addMinutes((int) config('whatsapp.media.id_lifetime_minutes')) : now()->addMinute();
    }

    public function handle(MediaArchiver $archiver): void
    {
        $m = WaMessage::find($this->messageId);
        // متساوية الأثر: المحفوظ أو المحسوم أو المحذوف لا يُعاد
        if (!$m || !in_array($m->media_status, ['pending', 'failed'], true) || $m->media_path || !$m->media_id) {
            return;
        }
        if ($archiver->lowOnDisk()) {
            MediaArchiver::alertOnce('disk', '⚠️ القرص يكاد يمتلئ', 'أُجّلت أرشفة مرفقات العملاء ساعة لأن المساحة الحرة على الخادم أقل من الحد الأدنى.');
            $this->release(3600);

            return;
        }
        if ($archiver->overQuota()) {
            $m->update(['media_status' => 'skipped_quota']);
            MediaArchiver::alertOnce('quota', '⚠️ أرشيف المرفقات بلغ سقفه', 'لم تعد المرفقات الجديدة تُحفظ حتى يُرفع السقف (WA_MEDIA_QUOTA_MB) أو تُقلَّم القديمة.');

            return;
        }

        $started = microtime(true);
        $result = $archiver->archive($m, $this->capOverride ?? (int) config('whatsapp.media.max_bytes'));
        if ($result['retry'] ?? false) {
            WaLog::warning('media.archive.retry', ['message_id' => $m->id, 'conv_id' => $m->conversation_id, 'code' => $result['error'] ?? null]);
            $this->release($this->backoff[max(0, $this->attempts() - 1)] ?? end($this->backoff));

            return;
        }
        $archiver->apply($m, $result);
        WaLog::info('media.archive', ['message_id' => $m->id, 'conv_id' => $m->conversation_id, 'status' => $result['status'],
            'bytes' => $result['size'] ?? null, 'ms' => (int) ((microtime(true) - $started) * 1000)]);
    }

    /** لا رسالة للعميل: الموظف يرى «تعذّر الحفظ» وزر التنزيل اليدوي خلال الأيام السبعة */
    public function failed(?\Throwable $e): void
    {
        $code = $e instanceof MaxAttemptsExceededException ? 'RETRY_EXPIRED' : class_basename($e ?? 'Unknown');
        WaMessage::whereKey($this->messageId)->whereIn('media_status', ['pending', 'failed'])
            ->update(['media_status' => 'failed', 'media_error' => mb_substr($code, 0, 32)]);
    }
}
