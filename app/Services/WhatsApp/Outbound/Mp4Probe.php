<?php

namespace App\Services\WhatsApp\Outbound;

/**
 * فحص ترميز فيديو MP4/3GP في PHP بلا ffmpeg (E32.4): يقرأ صناديق ISO-BMFF من أعلى الملف بالقفز (fseek) لا بالقراءة
 * الكاملة، ثم صندوق moov وحده (حتى 4MB، في أول الملف أو آخره) وفيه لكل مسار: hdlr (فيديو أو صوت) وأول مدخل في stsd
 * (الترميز). واتساب يقبل H.264 (avc1/avc3) مع AAC (mp4a) بمسار صوتي واحد أو بلا صوت؛ HEVC وغيره يُرفض قبل الرفع
 * لأن فشل الترميز يصل من ميتا متأخراً في ويبهوك الحالة. الملف الناقص أو التالف خطأ مقروء لا استثناء.
 */
final class Mp4Probe
{
    private const MAX_MOOV = 4 * 1024 * 1024;

    private const MAX_TOP_BOXES = 4096;

    private const VIDEO_OK = ['avc1', 'avc3'];

    private const HEVC = ['hvc1', 'hev1', 'dvh1', 'dvhe'];

    /**
     * @return array{ok: bool, video: ?string, audio: list<string>, faststart: bool, code: ?string, error: ?string}
     */
    public static function inspect(string $path): array
    {
        $fh = is_file($path) ? @fopen($path, 'rb') : false;
        if ($fh === false) {
            return self::fail('VIDEO_UNREADABLE', 'تعذّر قراءة ملف الفيديو.');
        }
        try {
            $size = (int) filesize($path);
            $offset = 0;
            $moov = null;
            $moovAt = null;
            $mdatAt = null;
            for ($i = 0; $offset + 8 <= $size && $i < self::MAX_TOP_BOXES; $i++) {
                fseek($fh, $offset);
                $head = (string) fread($fh, 8);
                if (strlen($head) < 8) {
                    break;
                }
                $len = unpack('N', substr($head, 0, 4))[1];
                $type = substr($head, 4, 4);
                $headerLen = 8;
                if ($len === 1) {
                    $large = (string) fread($fh, 8);
                    if (strlen($large) < 8) {
                        return self::truncated();
                    }
                    $len = self::u64($large);
                    $headerLen = 16;
                } elseif ($len === 0) {
                    $len = $size - $offset;
                }
                if ($len < $headerLen || !preg_match('/^[\x20-\x7E]{4}$/', $type)) {
                    return self::truncated();
                }
                if ($offset + $len > $size) {
                    return self::truncated();
                }
                if ($type === 'moov') {
                    if ($len - $headerLen > self::MAX_MOOV) {
                        return self::fail('VIDEO_TOO_COMPLEX', 'تعذّر فحص الفيديو (فهرسه كبير جداً). صدّره من جديد بـ H.264 أو أرسله رابطاً.');
                    }
                    fseek($fh, $offset + $headerLen);
                    $moov = (string) fread($fh, $len - $headerLen);
                    $moovAt = $offset;
                } elseif ($type === 'mdat' && $mdatAt === null) {
                    $mdatAt = $offset;
                }
                $offset += $len;
            }
        } finally {
            fclose($fh);
        }

        if ($moov === null) {
            return self::truncated();
        }

        $video = [];
        $audio = [];
        foreach (self::children($moov) as [$type, $trak]) {
            if ($type !== 'trak') {
                continue;
            }
            $track = self::track($trak);
            if ($track === null) {
                continue;
            }
            if ($track['handler'] === 'vide') {
                $video[] = $track['format'];
            } elseif ($track['handler'] === 'soun') {
                $audio[] = $track['format'];
            }
        }

        $faststart = $mdatAt === null || $moovAt < $mdatAt;
        $result = ['video' => $video[0] ?? null, 'audio' => $audio, 'faststart' => $faststart];
        if ($video === []) {
            return $result + self::fail('VIDEO_NO_TRACK', 'الملف لا يحوي مسار فيديو.');
        }
        $codec = (string) $video[0];
        if (in_array($codec, self::HEVC, true)) {
            return $result + self::fail('VIDEO_HEVC', 'الفيديو بترميز HEVC؛ صدّره بـ H.264 أو أرسله رابطاً.');
        }
        if (!in_array($codec, self::VIDEO_OK, true)) {
            return $result + self::fail('VIDEO_CODEC', "ترميز الفيديو ({$codec}) لا يقبله واتساب؛ صدّره بـ H.264 أو أرسله رابطاً.");
        }
        if (count($audio) > 1) {
            return $result + self::fail('VIDEO_AUDIO_TRACKS', 'الفيديو فيه أكثر من مسار صوتي؛ واتساب يقبل مساراً واحداً. صدّره من جديد.');
        }
        if ($audio !== [] && $audio[0] !== 'mp4a') {
            return $result + self::fail('VIDEO_AUDIO_CODEC', "ترميز الصوت ({$audio[0]}) لا يقبله واتساب؛ صدّر الفيديو بصوت AAC.");
        }

        return ['ok' => true, 'code' => null, 'error' => null] + $result;
    }

    /** @return array{handler: string, format: string}|null */
    private static function track(string $trak): ?array
    {
        $mdia = self::child($trak, 'mdia');
        if ($mdia === null) {
            return null;
        }
        $hdlr = self::child($mdia, 'hdlr');
        // fullbox: الإصدار والأعلام (4) ثم pre_defined (4) ثم نوع المعالج (4)
        $handler = $hdlr !== null && strlen($hdlr) >= 12 ? substr($hdlr, 8, 4) : '';
        $stsd = self::path($mdia, ['minf', 'stbl', 'stsd']);
        // fullbox (4) ثم عدد المدخلات (4) ثم أول مدخل: حجمه (4) ونوعه (4) وهو الترميز
        $format = $stsd !== null && strlen($stsd) >= 16 ? substr($stsd, 12, 4) : '';

        return ['handler' => $handler, 'format' => preg_replace('/[^\x20-\x7E]/', '?', $format) ?? ''];
    }

    private static function path(string $data, array $types): ?string
    {
        foreach ($types as $type) {
            $data = self::child($data, $type);
            if ($data === null) {
                return null;
            }
        }

        return $data;
    }

    private static function child(string $data, string $type): ?string
    {
        foreach (self::children($data) as [$t, $payload]) {
            if ($t === $type) {
                return $payload;
            }
        }

        return null;
    }

    /** @return \Generator<array{0: string, 1: string}> */
    private static function children(string $data): \Generator
    {
        $offset = 0;
        $total = strlen($data);
        for ($i = 0; $offset + 8 <= $total && $i < 1024; $i++) {
            $len = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            $headerLen = 8;
            if ($len === 1) {
                if ($offset + 16 > $total) {
                    return;
                }
                $len = self::u64(substr($data, $offset + 8, 8));
                $headerLen = 16;
            } elseif ($len === 0) {
                $len = $total - $offset;
            }
            if ($len < $headerLen || $offset + $len > $total) {
                return;
            }
            yield [$type, substr($data, $offset + $headerLen, $len - $headerLen)];
            $offset += $len;
        }
    }

    private static function u64(string $bytes): int
    {
        $parts = unpack('Nhi/Nlo', $bytes);

        return $parts['hi'] > 0x7FFFFFFF ? PHP_INT_MAX : ($parts['hi'] << 32) | $parts['lo'];
    }

    private static function truncated(): array
    {
        return self::fail('VIDEO_TRUNCATED', 'تعذّر قراءة الفيديو (ملف ناقص أو ليس MP4 سليماً). صدّره من جديد.');
    }

    private static function fail(string $code, string $error): array
    {
        return ['ok' => false, 'video' => null, 'audio' => [], 'faststart' => false, 'code' => $code, 'error' => $error];
    }
}
