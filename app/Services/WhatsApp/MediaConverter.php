<?php

namespace App\Services\WhatsApp;

use App\Exceptions\ConverterBusyException;
use App\Exceptions\VoiceConversionException;
use App\Exceptions\VoiceTooLongException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * تسجيل المتصفح إلى رسالة واتساب صوتية (E34.3): ogg/opus أحادي 48kHz بمعدل 32k، الصيغة الوحيدة التي تصل العميل رسالة
 * صوتية بأيقونة الميكروفون. Chrome يسجّل webm/opus، وSafari mp4/AAC، وFirefox ogg/opus، والتحويل ترميز كامل دائماً
 * (قناة واحدة ومعدل ثابت حتى لملف Firefox).
 *
 * الحماية: تحويل واحد على الحاوية بقفل (الثاني 503)، ومهلة 45 ثانية (تحت كتلة nginx للمسار)، وProcess بوسائط مصفوفة لا shell، وبيئة PATH وحدها
 * (لا أسرار التطبيق في عملية ffmpeg)، وأسماء uuid يولّدها الخادم في مجلد 0700. ملف الإدخال يُحذف دائماً، والناتج يُحذف
 * إن فشل أي شيء بعده. الأخطاء في سجل الواتساب برمز الخروج وstderr مقصوصاً، ولا يصل stderr الواجهة.
 *
 * في الاختبارات: fake() يبدّل تشغيل العمليات (ffprobe وffmpeg) بدالة، فلا يعتمد الاختبار على وجود ffmpeg.
 */
final class MediaConverter
{
    public const LOCK = 'wa:ffmpeg';

    private const STDERR_MAX = 500;

    private const PATH_ENV = '/usr/local/bin:/usr/bin:/bin';

    /** @var (callable(list<string>, float): array)|null */
    private static $runner = null;

    /**
     * للاختبارات: الدالة تستقبل وسائط العملية ومهلتها وتعيد {exit, out, err, timed_out} (null يعيد الحقيقي).
     * التحويل يكتب الناتج في آخر وسيط.
     */
    public static function fake(?callable $runner): void
    {
        self::$runner = $runner;
    }

    /** ffmpeg وffprobe موجودان (inbox يخفي زر التسجيل بدونهما) */
    public static function available(): bool
    {
        return self::$runner !== null || (is_executable(self::ffmpeg()) && is_executable(self::ffprobe()));
    }

    /** السطر الأول من ffmpeg -version لـ whatsapp:doctor (مهلة 5 ثوانٍ)، أو null */
    public static function version(): ?string
    {
        if (!self::available()) {
            return null;
        }
        $r = self::run([self::ffmpeg(), '-hide_banner', '-version'], 5);
        $line = trim((string) strtok((string) $r['out'], "\n"));

        return $r['exit'] === 0 && $line !== '' ? mb_substr($line, 0, 120) : null;
    }

    public static function tmpDir(): string
    {
        $dir = storage_path('app/tmp/voice');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /** مسار مؤقت باسم يولّده الخادم (لا يُستعمل اسم المتصفح أبداً) */
    public static function tmpPath(string $ext): string
    {
        return self::tmpDir().'/'.Str::uuid7().'.'.(preg_replace('/[^a-z0-9]/', '', strtolower($ext)) ?: 'bin');
    }

    /**
     * يحوّل ويقيس ويحذف الإدخال. المدة من الناتج (تسجيل Chrome بلا مدة في ترويسته)، والحد max_seconds بسماح 5 ثوانٍ.
     * ناتج فوق 512KB يُعاد ترميزه مرة بالمعدل الأدنى، ثم يُرفض.
     *
     * @return array{path: string, bytes: int, seconds: float, sha256: string}
     *
     * @throws ConverterBusyException|VoiceTooLongException|VoiceConversionException
     */
    public static function toVoice(string $inputPath): array
    {
        $lock = Cache::lock(self::LOCK, (int) config('whatsapp.voice.lock_seconds', 75));
        try {
            $lock->block((int) config('whatsapp.voice.lock_wait', 3));
        } catch (LockTimeoutException) {
            @unlink($inputPath);
            self::count('busy');
            WaLog::warning('wa.voice.busy');

            throw new ConverterBusyException;
        }

        $out = null;
        $ok = false;
        $started = microtime(true);
        try {
            if (!self::available()) {
                throw VoiceConversionException::unavailable();
            }
            $limit = (int) config('whatsapp.voice.max_seconds', 120) + 5;
            $declared = self::probe($inputPath, 'VOICE_INPUT_INVALID');
            if ($declared !== null && $declared > $limit) {
                throw new VoiceTooLongException;
            }

            $out = self::tmpPath('ogg');
            self::encode($inputPath, $out, self::bitrate((string) config('whatsapp.voice.bitrate', '32k')), $limit + 1);
            $seconds = self::probe($out, 'VOICE_OUTPUT_INVALID');
            if ($seconds === null) {
                throw VoiceConversionException::failed('VOICE_OUTPUT_INVALID');
            }
            if ($seconds > $limit) {
                throw new VoiceTooLongException;
            }
            $bytes = self::size($out);
            if ($bytes > (int) config('whatsapp.voice.max_voice_bytes', 524288)) {
                self::encode($inputPath, $out, self::bitrate((string) config('whatsapp.voice.fallback_bitrate', '24k')), $limit + 1);
                $bytes = self::size($out);
                if ($bytes > (int) config('whatsapp.voice.max_voice_bytes', 524288)) {
                    throw new VoiceTooLongException('VOICE_TOO_LARGE');
                }
            }

            $ms = (int) round((microtime(true) - $started) * 1000);
            self::count('ok', $ms);
            WaLog::info('wa.voice.converted', ['bytes' => $bytes, 'seconds' => round($seconds, 1), 'ms' => $ms]);
            $ok = true;

            return ['path' => $out, 'bytes' => $bytes, 'seconds' => round($seconds, 2), 'sha256' => (string) hash_file('sha256', $out)];
        } catch (VoiceConversionException $e) {
            self::count('failed');

            throw $e;
        } finally {
            @unlink($inputPath);
            if (!$ok && $out !== null) {
                @unlink($out);
            }
            $lock->release();
        }
    }

    /**
     * بيئة العملية: PATH وحده. كل متغير آخر (APP_KEY ورموز ميتا ومفتاح النموذج) يُمرَّر false فلا يرثه ffmpeg.
     *
     * @return array<string, string|false>
     */
    public static function processEnv(): array
    {
        $keys = array_filter(array_keys($_ENV + (getenv() ?: [])), 'is_string');
        $env = array_fill_keys($keys, false);
        $env['PATH'] = self::PATH_ENV;

        return $env;
    }

    /**
     * وسائط التحويل: مصفوفة لا سلسلة shell. -nostdin، وبلا فيديو ولا ترجمات ولا بيانات وصفية (اسم الجهاز والتاريخ)،
     * وقص عند الحد بسماح، وترميز libopus بنمط voip.
     *
     * @return list<string>
     */
    public static function encodeArguments(string $in, string $out, string $bitrate, int $capSeconds): array
    {
        return [self::ffmpeg(), '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-i', $in, '-t', (string) $capSeconds,
            '-vn', '-sn', '-dn', '-map_metadata', '-1', '-ac', '1', '-ar', '48000', '-c:a', 'libopus', '-b:a', $bitrate,
            '-application', 'voip', '-f', 'ogg', $out];
    }

    /** أعداد اليوم لـ whatsapp:doctor: نجاح ومتوسط زمنه، و«مشغول» (503)، وفشل */
    public static function stats(): array
    {
        $day = now()->format('Ymd');
        $ok = (int) Cache::get("wa:voice:ok:{$day}", 0);

        return [
            'ok' => $ok,
            'busy' => (int) Cache::get("wa:voice:busy:{$day}", 0),
            'failed' => (int) Cache::get("wa:voice:failed:{$day}", 0),
            'avg_ms' => $ok > 0 ? intdiv((int) Cache::get("wa:voice:ms:{$day}", 0), $ok) : null,
        ];
    }

    private static function encode(string $in, string $out, string $bitrate, int $capSeconds): void
    {
        $r = self::run(self::encodeArguments($in, $out, $bitrate, $capSeconds), (float) config('whatsapp.voice.timeout', 45));
        clearstatcache(true, $out);
        if ($r['timed_out']) {
            self::logFailure('VOICE_CONVERT_TIMEOUT', $r);

            throw VoiceConversionException::failed('VOICE_CONVERT_TIMEOUT');
        }
        if ($r['exit'] !== 0 || !is_file($out) || filesize($out) === 0) {
            self::logFailure('VOICE_CONVERT_FAILED', $r);

            throw VoiceConversionException::failed();
        }
    }

    /** المدة بالثواني، أو null إن لم تُعرف (webm من MediaRecorder بلا مدة في ترويسته) */
    private static function probe(string $path, string $reason): ?float
    {
        $r = self::run([self::ffprobe(), '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path],
            (float) config('whatsapp.voice.probe_timeout', 5));
        if ($r['timed_out'] || $r['exit'] !== 0) {
            self::logFailure($reason, $r);

            throw VoiceConversionException::failed($reason);
        }
        $value = trim((string) $r['out']);

        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }

    /** @return array{exit: int, out: string, err: string, timed_out: bool} */
    private static function run(array $command, float $timeout): array
    {
        $base = ['exit' => 0, 'out' => '', 'err' => '', 'timed_out' => false];
        if (self::$runner !== null) {
            return (array) (self::$runner)($command, $timeout) + $base;
        }
        $process = new Process($command, self::tmpDir(), self::processEnv(), null, $timeout);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return ['exit' => -1, 'out' => '', 'err' => 'timeout', 'timed_out' => true];
        }

        return ['exit' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput(), 'timed_out' => false];
    }

    /** رمز الخروج وstderr مقصوصاً إلى 500 حرف بلا مسار المجلد المؤقت ولا محارف تحكم */
    private static function logFailure(string $reason, array $r): void
    {
        $err = str_replace(self::tmpDir(), '<tmp>', (string) ($r['err'] ?? ''));
        $err = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', ' ', $err) ?? '';
        WaLog::warning('wa.voice.failed', ['reason' => $reason, 'exit' => (int) ($r['exit'] ?? -1), 'stderr' => mb_substr(trim($err), 0, self::STDERR_MAX)]);
    }

    private static function size(string $path): int
    {
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : 0;
    }

    private static function bitrate(string $value): string
    {
        return preg_match('/^\d{1,3}k$/', $value) ? $value : '32k';
    }

    private static function count(string $kind, int $ms = 0): void
    {
        rescue(function () use ($kind, $ms) {
            $day = now()->format('Ymd');
            foreach ($kind === 'ok' ? ["wa:voice:ok:{$day}" => 1, "wa:voice:ms:{$day}" => $ms] : ["wa:voice:{$kind}:{$day}" => 1] as $key => $by) {
                Cache::add($key, 0, now()->addDays(2));
                Cache::increment($key, $by);
            }
        }, null, false);
    }

    private static function ffmpeg(): string
    {
        return (string) config('whatsapp.voice.ffmpeg_path', '/usr/bin/ffmpeg');
    }

    private static function ffprobe(): string
    {
        return (string) config('whatsapp.voice.ffprobe_path', '/usr/bin/ffprobe');
    }
}
