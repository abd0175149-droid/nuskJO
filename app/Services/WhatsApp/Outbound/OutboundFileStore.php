<?php

namespace App\Services\WhatsApp\Outbound;

use App\Services\WhatsApp\Media\MediaArchiver;
use Illuminate\Support\Str;

/**
 * النسخة الخاصة من كل ملف صادر (E32.6) على قرص wa_media (E14: خاص، serve=false، 0600/0700، خارج /storage العام):
 * outbound/{Y}/{m}/{uuid7}.{ext} بلا اسم أصلي ولا هاتف ولا رقم فاتورة. المسار يُكتب في wa_messages.media_path بحالة
 * stored، فيعرضه عارض E14 لمن يملك الرؤية، ويقلّمه whatsapp:media-prune بمدة الاحتفاظ نفسها، ويحذفه whatsapp:forget،
 * ويكنس media-sweep اليتيم منه بعد ساعة (ملف لا يشير إليه صف).
 */
final class OutboundFileStore
{
    public const DIR = 'outbound';

    /**
     * @return array{path: string, sha256: string, size: int}
     */
    public static function put(string $sourcePath, string $ext): array
    {
        $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'bin');
        $tmp = MediaArchiver::tmpDir().'/'.Str::uuid7().'.part';
        if (!@copy($sourcePath, $tmp)) {
            throw new \RuntimeException('OUTBOUND_COPY_FAILED');
        }
        try {
            $sha = (string) hash_file('sha256', $tmp);
            $size = (int) filesize($tmp);
            $path = self::DIR.'/'.now()->format('Y/m').'/'.Str::uuid7().'.'.$ext;
            $disk = MediaArchiver::disk();
            if (config('filesystems.disks.'.config('whatsapp.media.disk').'.driver') === 'local') {
                $target = $disk->path($path);
                if (!is_dir(dirname($target))) {
                    @mkdir(dirname($target), 0700, true);
                }
                if (!@rename($tmp, $target)) {
                    throw new \RuntimeException('OUTBOUND_MOVE_FAILED');
                }
                @chmod($target, 0600);
            } else {
                $stream = fopen($tmp, 'rb');
                try {
                    $disk->writeStream($path, $stream);
                } finally {
                    is_resource($stream) && fclose($stream);
                }
            }

            return ['path' => $path, 'sha256' => $sha, 'size' => $size];
        } finally {
            @unlink($tmp);
        }
    }

    public static function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            rescue(fn () => MediaArchiver::disk()->delete($path), null, false);
        }
    }

    public static function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && MediaArchiver::disk()->exists($path);
    }

    /**
     * مسار محلي يُفتح بـ fopen للرفع: القرص المحلي مباشرة، وغيره (R2 لاحقاً) نسخة مؤقتة يحذفها المستدعي بـ release().
     *
     * @return array{0: string, 1: bool} [المسار، مؤقت؟]
     */
    public static function local(string $path): array
    {
        $disk = MediaArchiver::disk();
        if (config('filesystems.disks.'.config('whatsapp.media.disk').'.driver') === 'local') {
            return [$disk->path($path), false];
        }
        $tmp = MediaArchiver::tmpDir().'/'.Str::uuid7().'.part';
        $in = $disk->readStream($path);
        $out = fopen($tmp, 'wb');
        try {
            stream_copy_to_stream($in, $out);
        } finally {
            is_resource($in) && fclose($in);
            is_resource($out) && fclose($out);
        }

        return [$tmp, true];
    }

    /** يحذف النسخة المؤقتة من local() إن كانت مؤقتة */
    public static function release(array $local): void
    {
        if (($local[1] ?? false) === true) {
            @unlink($local[0]);
        }
    }
}
