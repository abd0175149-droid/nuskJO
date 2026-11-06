<?php

namespace App\Services\WhatsApp\Media;

use App\Models\WaBotSetting;
use App\Models\WaMessage;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Services\WhatsApp\WhatsAppInbox;
use App\Support\FeatureFlags;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * أرشفة وسيط وارد (E14.4). منذ 2026-11-06 من بوابة OpenWA: GET /messages/{chatId}/{id}/media بمفتاح رقم الرسالة
 * (WhatsAppChannel::fetchMedia) بسقف الحجم، ثم ملف مؤقت، والنوع بـ finfo من قائمة بيضاء، ثم نقل ذري إلى wa_media بمسار
 * {Y}/{m}/{uuid7}.{ext} بلا اسم ولا رقم. لا بصمة من المصدر (البوابة لا ترسلها): sha256 تُحسب هنا وتُحفظ.
 * الفشل نتيجة (retry) لا رمي.
 */
final class MediaArchiver
{
    /** حالات الأرشفة في wa_messages.media_status */
    public const STATES = ['pending', 'stored', 'too_large', 'failed', 'expired', 'rejected', 'purged', 'missing', 'skipped_quota', 'skipped_consent'];

    /**
     * النتيجة، أو retry=true: أعد المحاولة لاحقاً (backoff).
     *
     * @return array{status?: string, error?: ?string, size?: int, path?: string, mime?: string, retry?: bool}
     */
    public function archive(WaMessage $m, int $cap): array
    {
        $fetched = WhatsAppChannel::fetchMedia((string) $m->media_id, 'any', $cap, $m->id);
        if (!$fetched['ok']) {
            return match ($fetched['code']) {
                'MEDIA_EXPIRED' => ['status' => 'expired', 'error' => 'MEDIA_EXPIRED'],
                WhatsAppChannel::ERR_TOO_LARGE => ['status' => 'too_large', 'size' => $cap + 1],
                WhatsAppChannel::ERR_NO_CHANNEL, WhatsAppChannel::ERR_NO_RECIPIENT => ['status' => 'failed', 'error' => $fetched['code']],
                default => ['retry' => true, 'error' => (string) $fetched['code']],
            };
        }

        $tmp = self::tmpDir().'/'.Str::uuid7().'.part';
        try {
            if (@file_put_contents($tmp, $fetched['bytes']) === false) {
                return ['retry' => true, 'error' => 'TMP_WRITE'];
            }
            unset($fetched['bytes']);
            $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $type = self::allowedTypes()[$detected] ?? null;
            if ($type === null) {
                return ['status' => 'rejected', 'error' => 'MIME_'.substr(md5($detected), 0, 6)];
            }
            $size = (int) filesize($tmp);
            $sha = (string) hash_file('sha256', $tmp);
            $path = $this->place($tmp, $m->created_at ?? now(), $type[0]);

            return ['status' => 'stored', 'path' => $path, 'mime' => $detected, 'size' => $size, 'sha256' => $sha, 'error' => null];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * يكتب النتيجة في الرسالة في معاملة قصيرة. إن حُذفت الرسالة أو تغيّرت حالتها أثناء التنزيل (forget أو «احذف ملفاتي»)
     * يُحذف الملف الجديد ولا يبقى يتيماً.
     */
    public function apply(WaMessage $m, array $result): void
    {
        $status = $result['status'];
        $update = ['media_status' => $status, 'media_error' => $result['error'] ?? null];
        if (isset($result['size'])) {
            $update['media_size'] = min((int) $result['size'], 4_294_967_295);
        }
        if ($status === 'stored') {
            $update += ['media_path' => $result['path'], 'media_mime' => $result['mime'], 'media_archived_at' => now()]
                + (isset($result['sha256']) ? ['media_sha256' => $result['sha256']] : []);
        }

        $affected = DB::transaction(fn () => WaMessage::whereKey($m->id)->whereIn('media_status', ['pending', 'failed'])->whereNull('media_path')->update($update));
        if ($affected === 0 && $status === 'stored') {
            self::disk()->delete($result['path']);
        }
    }

    /** نسخ ملف محلي (بطاقة عرض صادرة، E14.11؛ ومرفقات الموظف في E32) إلى الأرشيف وتعليم رسالته stored. النوع بالفحص */
    public function storeOutbound(string $wamid, string $sourceFile, ?string $expectedMime = null): bool
    {
        $m = $wamid !== '' ? WaMessage::where('wamid', $wamid)->first() : null;
        if (!$m || !is_file($sourceFile)) {
            return false;
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($sourceFile);
        $type = self::allowedTypes()[$mime] ?? null;
        if ($type === null || ($expectedMime !== null && $expectedMime !== $mime)) {
            WaLog::warning('media.outbound.type', ['message_id' => $m->id]);

            return false;
        }
        $tmp = self::tmpDir().'/'.Str::uuid7().'.part';
        try {
            if (!@copy($sourceFile, $tmp)) {
                return false;
            }
            $path = $this->place($tmp, $m->created_at ?? now(), $type[0]);
            $m->forceFill(['media_path' => $path, 'media_mime' => $mime, 'media_size' => (int) filesize(self::disk()->path($path)),
                'media_status' => 'stored', 'media_archived_at' => now(), 'media_error' => null])->save();

            return true;
        } catch (\Throwable $e) {
            WaLog::warning('media.outbound.failed', ['message_id' => $m->id, 'type' => class_basename($e)]);

            return false;
        } finally {
            @unlink($tmp);
        }
    }

    /** أقل من النسبة الدنيا حرة على قرص التخزين */
    public function lowOnDisk(): bool
    {
        $total = @disk_total_space(storage_path());
        $free = @disk_free_space(storage_path());

        return $total && $free !== false && $free / $total * 100 < (float) config('whatsapp.media.min_free_pct', 5);
    }

    /** مجموع المحفوظ بالبايت (للسقف ولبطاقة الإعدادات) */
    public static function usedBytes(): int
    {
        return (int) WaMessage::where('media_status', 'stored')->sum('media_size');
    }

    public function overQuota(): bool
    {
        return Cache::get('wa:media:quota_exceeded') || self::usedBytes() >= (int) config('whatsapp.media.quota_mb') * 1024 * 1024;
    }

    /** بطاقة «أرشيف المرفقات» في الإعدادات (E14.12): العلمان والمساحة وأعداد آخر 7 أيام لكل حالة. أعداد فقط */
    public static function summary(): ?array
    {
        if (!Schema::hasColumn('wa_messages', 'media_status')) {
            return null;
        }
        $quota = (int) config('whatsapp.media.quota_mb');
        $used = self::usedBytes();

        return [
            'enabled' => FeatureFlags::on('media_archive'),
            'consent_prompt' => FeatureFlags::on('media_consent_prompt'),
            'used_mb' => round($used / 1048576, 1),
            'quota_mb' => $quota,
            'pct' => $quota > 0 ? (int) floor($used / ($quota * 1048576) * 100) : 0,
            'retention_days' => (int) config('whatsapp.media.retention_days'),
            'sha_mode' => (string) config('whatsapp.media.sha_mode'),
            'paused' => (bool) Cache::get('wa:media:quota_exceeded'),
            'week' => WaMessage::whereNotNull('media_status')->where('created_at', '>=', now()->subDays(7))
                ->selectRaw('media_status, count(*) n')->groupBy('media_status')->pluck('n', 'media_status')->map(fn ($n) => (int) $n)->all(),
        ];
    }

    /** @return array<string, array{0: string, 1: bool}> النوع المكتشف => [الامتداد، يُعاين] */
    public static function allowedTypes(): array
    {
        $types = (array) config('whatsapp.media.mimes', []);

        return config('whatsapp.media.allow_office') ? $types + (array) config('whatsapp.media.office_mimes', []) : $types;
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(config('whatsapp.media.disk'));
    }

    public static function tmpDir(): string
    {
        $dir = storage_path('app/wa-media/.tmp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    public static function alertOnce(string $kind, string $title, string $body): void
    {
        if (Cache::add("wa:media:alert:{$kind}:".now()->toDateString(), 1, now()->addDay())) {
            // مضيف غير متوقع إعداد يُراجَع (RB-17)، والباقي سقف الأرشيف أو القرص أو ملفات مفقودة (RB-08)
            WhatsAppInbox::notifyAdmins($title, $body, '/whatsapp/settings#media', $kind === 'host' ? 'RB-17' : 'RB-08');
        }
    }

    /** نقل ذري إلى القرص المحلي (rename ثم 0600)، أو writeStream لغيره */
    private function place(string $tmp, Carbon|\DateTimeInterface $at, string $ext): string
    {
        $path = Carbon::instance($at)->format('Y/m').'/'.Str::uuid7().'.'.$ext;
        $disk = self::disk();
        if (config('filesystems.disks.'.config('whatsapp.media.disk').'.driver') === 'local') {
            $target = $disk->path($path);
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0700, true);
            }
            if (!@rename($tmp, $target)) {
                throw new \RuntimeException('MEDIA_MOVE_FAILED');
            }
            @chmod($target, 0600);

            return $path;
        }
        $stream = fopen($tmp, 'rb');
        try {
            $disk->writeStream($path, $stream);
        } finally {
            is_resource($stream) && fclose($stream);
        }

        return $path;
    }
}
