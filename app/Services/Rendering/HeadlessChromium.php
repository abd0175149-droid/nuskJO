<?php

namespace App\Services\Rendering;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * كروميوم بلا واجهة (مستخرج من OfferCardService في E32.8): لقطة PNG لبطاقة العرض، وطباعة PDF لفاتورة العميل.
 *
 * القالب ملف محلي يعمل بلا شبكة (data: وسياسة CSP فيه)، وأعلام عزل الشبكة من E05 طبقة ثانية (CHROMIUM_NET_ISOLATION).
 * php-fpm يعمل بـ www-data بلا مجلد منزل، فلكل تشغيل ملف تعريف ومنزل مؤقتان يُحذفان دائماً، ومهلة 60 ثانية.
 */
final class HeadlessChromium
{
    public const TIMEOUT = 60;

    /** @var (callable(string, string, string): void)|null بديل في الاختبارات وحدها */
    private static $fake = null;

    /** للاختبارات: يكتب الملف بدل كروميوم (null يعيد الحقيقي) */
    public static function fake(?callable $writer): void
    {
        self::$fake = $writer;
    }

    /** يلتقط الصفحة PNG بالمقاس المعطى (بطاقة العرض) */
    public static function screenshot(string $htmlPath, string $pngPath, int $width, int $height, string $label = 'البطاقة'): void
    {
        if (self::$fake !== null) {
            (self::$fake)($htmlPath, $pngPath, 'png');

            return;
        }
        $profile = self::profile();
        self::run(self::screenshotArguments(self::binary(), $profile, $htmlPath, $pngPath, $width, $height), $profile, $pngPath,
            "انتهت مهلة توليد {$label} (60 ثانية).", 'تعذّر تشغيل مُصيّر الصور. راجع سجلّ الأخطاء.');
    }

    /** يطبع الصفحة PDF بقياس @page في القالب، بلا ترويسة المتصفح وتذييله (فاتورة PDF) */
    public static function printToPdf(string $htmlPath, string $pdfPath): void
    {
        if (self::$fake !== null) {
            (self::$fake)($htmlPath, $pdfPath, 'pdf');

            return;
        }
        $profile = self::profile();
        self::run(self::pdfArguments(self::binary(), $profile, $htmlPath, $pdfPath), $profile, $pdfPath,
            'انتهت مهلة توليد ملف PDF (60 ثانية).', 'تعذّر توليد ملف PDF. راجع سجلّ الأخطاء.');
    }

    /** @return list<string> بترتيب OfferCardService القديم نفسه (OfferCardIsolationTest) */
    public static function screenshotArguments(string $binary, string $profile, string $htmlPath, string $pngPath, int $width, int $height): array
    {
        return [
            ...self::base($binary),
            '--disable-software-rasterizer',
            '--hide-scrollbars',
            '--force-device-scale-factor=1',
            '--default-background-color=FFFFFFFF',
            '--user-data-dir='.$profile,
            '--no-first-run',
            '--no-default-browser-check',
            '--window-size='.$width.','.$height,
            '--virtual-time-budget=8000',   // ننتظر الخطوط وسكربت ضبط المقاس
            ...self::isolation(),
            '--screenshot='.$pngPath,
            self::fileUrl($htmlPath),
        ];
    }

    /** @return list<string> */
    public static function pdfArguments(string $binary, string $profile, string $htmlPath, string $pdfPath): array
    {
        return [
            ...self::base($binary),
            '--user-data-dir='.$profile,
            '--no-first-run',
            '--no-default-browser-check',
            '--no-pdf-header-footer',
            '--virtual-time-budget=8000',
            ...self::isolation(),
            '--print-to-pdf='.$pdfPath,
            self::fileUrl($htmlPath),
        ];
    }

    /** مسار كروميوم — يُضبط بـ CHROMIUM_PATH عند الحاجة */
    public static function binary(): string
    {
        $configured = config('services.chromium.path');
        if ($configured && is_executable($configured)) {
            return $configured;
        }
        foreach (['/usr/bin/chromium-browser', '/usr/bin/chromium', '/usr/bin/google-chrome', '/usr/bin/chrome'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('لم يُعثر على كروميوم داخل الحاوية — توليد البطاقات وPDF يحتاج حزمة chromium.');
    }

    /** @return list<string> */
    private static function base(string $binary): array
    {
        return [
            $binary,
            '--headless',
            '--no-sandbox',                 // نعمل داخل حاوية معزولة
            '--disable-gpu',
            '--disable-dev-shm-usage',      // /dev/shm صغيرة في الحاويات
        ];
    }

    /**
     * عزل عن الشبكة: بروكسي ميت يشمل loopback (Reverb وphp-fpm وnginx على المضيف نفسه)، وحلّ أسماء فاشل، ولا خدمات
     * خلفية. سياسة CSP في القالب هي الحاجز الأول؛ هذه طبقة ثانية لأي محتوى يفلت منها.
     *
     * @return list<string>
     */
    private static function isolation(): array
    {
        if (!config('services.chromium.net_isolation')) {
            return [];
        }

        return [
            '--proxy-server=127.0.0.1:9',
            '--proxy-bypass-list=<-loopback>',
            '--host-resolver-rules=MAP * ~NOTFOUND',
            '--disable-background-networking',
            '--disable-component-update',
            '--disable-sync',
            '--disable-domain-reliability',
            '--no-pings',
        ];
    }

    private static function fileUrl(string $htmlPath): string
    {
        return 'file://'.str_replace('\\', '/', $htmlPath);
    }

    private static function profile(): string
    {
        return sys_get_temp_dir().'/chromium-'.bin2hex(random_bytes(5));
    }

    /** @param list<string> $args */
    private static function run(array $args, string $profile, string $output, string $timeoutMessage, string $failMessage): void
    {
        $process = new Process($args);
        $process->setTimeout(self::TIMEOUT);
        $process->setEnv(['HOME' => $profile]);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new \RuntimeException($timeoutMessage, 0, $e);
        } finally {
            self::rmdir($profile);
        }

        if (!is_file($output)) {
            Log::error('فشل كروميوم في التصيير', [
                'exit' => $process->getExitCode(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 2000),
            ]);
            throw new \RuntimeException($failMessage);
        }
    }

    private static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
