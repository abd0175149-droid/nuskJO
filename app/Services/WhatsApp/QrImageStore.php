<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * صورة رمز QR من ميتا (E38): qr_image_url غير مضمون البقاء، فتُنزَّل فور الإنشاء وتُحفظ عندنا.
 *
 * - https إلزامي، والمضيف من قائمة مضيفات ميتا (WA_MEDIA_HOSTS)، ولا تحويلات، ومهلة 15 ثانية، وسقف 1MB أثناء القراءة.
 * - finfo يجب أن يعطي image/png للـ PNG وimage/svg+xml للـ SVG.
 * - PNG على قرص public (wa-qr/…png): صورة عامة للطباعة لا تحمل إلا رابط رقمنا.
 * - SVG على قرص local الخاص: قد يحمل سكربتاً، فلا يُخدم من /storage أبداً، ويُنزَّل مرفقاً بـ nosniff من المتحكم.
 * - بلا رمز وصول: الرابط عام موقّع، وليس نداء Graph.
 */
final class QrImageStore
{
    public const MAX_BYTES = 1_048_576;

    private const DIR = 'wa-qr';

    /** @return string|null المسار النسبي على قرصه، أو null عند أي رفض */
    public static function fetch(string $url, string $format = 'PNG'): ?string
    {
        $svg = strtoupper($format) === 'SVG';
        if (!WhatsAppChannel::isMetaMediaUrl($url)) {
            WaLog::warning('wa.qr_image.rejected', ['reason' => 'host', 'host' => strtolower((string) parse_url($url, PHP_URL_HOST))]);

            return null;
        }

        try {
            $res = Http::withoutRedirecting()->connectTimeout(10)->timeout(15)->withOptions(['stream' => true])->get($url);
        } catch (\Throwable $e) {
            WaLog::warning('wa.qr_image.rejected', ['reason' => 'connection', 'type' => class_basename($e)]);

            return null;
        }
        if ($res->status() !== 200) {
            WaLog::warning('wa.qr_image.rejected', ['reason' => 'http', 'http' => $res->status()]);

            return null;
        }
        if ((int) $res->header('Content-Length') > self::MAX_BYTES) {
            WaLog::warning('wa.qr_image.rejected', ['reason' => 'size']);

            return null;
        }
        $body = $res->toPsrResponse()->getBody();
        $bytes = '';
        while (!$body->eof()) {
            $bytes .= $body->read(65536);
            if (strlen($bytes) > self::MAX_BYTES) {
                $body->close();
                WaLog::warning('wa.qr_image.rejected', ['reason' => 'size']);

                return null;
            }
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if ($mime !== ($svg ? 'image/svg+xml' : 'image/png')) {
            WaLog::warning('wa.qr_image.rejected', ['reason' => 'type', 'type' => mb_substr($mime, 0, 40)]);

            return null;
        }

        $path = self::DIR.'/'.Str::uuid().($svg ? '.svg' : '.png');
        Storage::disk($svg ? 'local' : 'public')->put($path, $bytes);

        return $path;
    }

    /** يحذف صور الرمز من القرصين */
    public static function forget(?string $png, ?string $svg): void
    {
        if ($png) {
            Storage::disk('public')->delete($png);
        }
        if ($svg) {
            Storage::disk('local')->delete($svg);
        }
    }
}
