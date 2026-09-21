<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\OfferHotel;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * توليد بطاقة العرض التسويقية كصورة PNG.
 *
 * لماذا كروميوم؟ امتداد gd الموجود في الصورة لا يُشكّل الحروف العربية ولا
 * يضبط اتجاه النصّ — «عمرة رجب» تخرج حروفاً منفصلة مقلوبة. كروميوم يُصيّر
 * القالب كما يظهر في المتصفّح تماماً، فتبقى البطاقة قابلة للتعديل بـ CSS.
 *
 * القالب يعمل بلا شبكة إطلاقاً: الشعار والترويسة يُحقنان كـ data: URI.
 */
class OfferCardService
{
    /** أبعاد البطاقة: A4 بدقّة 150 نقطة/البوصة */
    private const WIDTH = 1240;
    private const HEIGHT = 1754;

    private const DISK = 'public';
    private const DIR = 'offer-cards';

    /** حدّ حجم الصورة التي تقبلها واتساب (5 ميغا) مع هامش أمان */
    private const MAX_BYTES = 4_500_000;

    /**
     * يولّد البطاقة ويحدّث العرض. يعيد المسار النسبي على قرص public.
     *
     * @throws \RuntimeException إذا تعذّر التصيير
     */
    public static function generate(Offer $offer): string
    {
        $offer->loadMissing('hotels');

        if ($offer->hotels->isEmpty()) {
            throw new \RuntimeException('لا يمكن توليد بطاقة لعرض بلا فنادق.');
        }

        $html = self::html($offer);

        $tmpDir = storage_path('app/offer-cards-tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $token = $offer->id . '-' . bin2hex(random_bytes(6));
        $htmlPath = "{$tmpDir}/card-{$token}.html";
        $pngPath = "{$tmpDir}/card-{$token}.png";

        file_put_contents($htmlPath, $html);

        try {
            self::shoot($htmlPath, $pngPath);

            if (!is_file($pngPath) || filesize($pngPath) < 1024) {
                throw new \RuntimeException('تعذّر توليد صورة البطاقة — الملف الناتج فارغ.');
            }
            if (filesize($pngPath) > self::MAX_BYTES) {
                Log::warning('بطاقة عرض أكبر من حدّ واتساب', [
                    'offer_id' => $offer->id, 'bytes' => filesize($pngPath),
                ]);
            }

            $target = self::DIR . "/offer-{$token}.png";
            Storage::disk(self::DISK)->put($target, file_get_contents($pngPath));

            // نحذف البطاقة المولّدة السابقة — البطاقة المرفوعة يدوياً ليست ملكنا
            if ($offer->card_path && $offer->card_path !== $target) {
                Storage::disk(self::DISK)->delete($offer->card_path);
            }

            $offer->forceFill([
                'card_path' => $target,
                'card_generated_at' => now(),
            ])->saveQuietly();

            return $target;
        } finally {
            @unlink($htmlPath);
            @unlink($pngPath);
        }
    }

    /** HTML البطاقة — يُستخدم أيضاً للمعاينة في المتصفّح قبل التوليد */
    public static function html(Offer $offer): string
    {
        $offer->loadMissing('hotels');

        return View::make('offers.card', [
            'offer' => $offer,
            'hotels' => $offer->hotels,
            'roomTypes' => self::cardRoomLabels(),
            'company' => CompanyInfo::all(),
            'logoUri' => self::dataUri(Setting::get('company_logo')),
            'heroUri' => self::dataUri($offer->hero_path ?: Setting::get('card_hero_path')),
            'minPrice' => $offer->priceFrom(),
            'fontFamily' => self::fontFamily(),
        ])->render();
    }

    /**
     * تسميات الغرف كما تكتبها الشركة في بطاقتها المطبوعة
     * («مفرده» و«رباعيه» بالهاء — مقصودة، لا تُصحَّح).
     */
    private static function cardRoomLabels(): array
    {
        return [
            'single' => 'مفرده',
            'double' => 'ثنائية',
            'triple' => 'ثلاثية',
            'quad'   => 'رباعيه',
        ];
    }

    private static function fontFamily(): string
    {
        return "'Noto Naskh Arabic', 'Noto Sans Arabic', 'Noto Kufi Arabic', 'DejaVu Sans', sans-serif";
    }

    /** يحوّل ملفاً على قرص public إلى data: URI ليعمل القالب بلا شبكة */
    private static function dataUri(?string $path): ?string
    {
        if (!$path || !Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $bytes = Storage::disk(self::DISK)->get($path);
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => null,
        };

        return $mime ? 'data:' . $mime . ';base64,' . base64_encode($bytes) : null;
    }

    /** يشغّل كروميوم بلا واجهة لالتقاط البطاقة */
    private static function shoot(string $htmlPath, string $pngPath): void
    {
        $binary = self::chromium();

        // كروميوم يحتاج مجلّد ملفّ تعريف قابلاً للكتابة، و www-data بلا مجلّد منزل
        // داخل الحاوية — فنمنحه مجلّداً مؤقتاً خاصاً بكل عملية ثم نحذفه.
        $profile = sys_get_temp_dir() . '/chromium-' . bin2hex(random_bytes(5));

        $process = new Process([
            $binary,
            '--headless',
            '--no-sandbox',                 // نعمل كـ root داخل حاوية معزولة
            '--disable-gpu',
            '--disable-dev-shm-usage',      // /dev/shm صغيرة في الحاويات
            '--disable-software-rasterizer',
            '--hide-scrollbars',
            '--force-device-scale-factor=1',
            '--default-background-color=FFFFFFFF',
            '--user-data-dir=' . $profile,
            '--no-first-run',
            '--no-default-browser-check',
            '--window-size=' . self::WIDTH . ',' . self::HEIGHT,
            '--virtual-time-budget=8000',   // ننتظر الخطوط وسكربت ضبط المقاس
            '--screenshot=' . $pngPath,
            'file://' . str_replace('\\', '/', $htmlPath),
        ]);

        $process->setTimeout(60);
        $process->setEnv(['HOME' => $profile]);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            self::rmdir($profile);
            throw new \RuntimeException('انتهت مهلة توليد البطاقة (60 ثانية).', 0, $e);
        }

        self::rmdir($profile);

        if (!is_file($pngPath)) {
            Log::error('فشل كروميوم في توليد البطاقة', [
                'exit' => $process->getExitCode(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 2000),
            ]);
            throw new \RuntimeException('تعذّر تشغيل مُصيّر الصور. راجع سجلّ الأخطاء.');
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

    /** مسار كروميوم — يُضبط بـ CHROMIUM_PATH عند الحاجة */
    private static function chromium(): string
    {
        $configured = config('services.chromium.path') ?: env('CHROMIUM_PATH');
        if ($configured && is_executable($configured)) {
            return $configured;
        }

        foreach ([
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
            '/usr/bin/google-chrome',
            '/usr/bin/chrome',
        ] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            'لم يُعثر على كروميوم داخل الحاوية — توليد البطاقات يحتاج حزمة chromium.'
        );
    }

    /** حذف كل صور العرض عند حذفه */
    public static function forget(Offer $offer): void
    {
        foreach ([$offer->card_path, $offer->custom_card_path, $offer->hero_path] as $p) {
            if ($p) {
                Storage::disk(self::DISK)->delete($p);
            }
        }
    }
}
