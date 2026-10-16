<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\Setting;
use App\Models\WaQrLink;
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
    /**
     * المقاسات: A4 بدقّة 150 نقطة/البوصة، وبطاقة الحالة 9:16 (E38) للنشر اليدوي. [العرض، الارتفاع، القالب]
     */
    public const VARIANTS = [
        'a4' => [1240, 1754, 'offers.card'],
        'story' => [1080, 1920, 'offers.card-story'],
    ];

    private const DISK = 'public';
    private const DIR = 'offer-cards';

    /** حدّ حجم الصورة التي تقبلها واتساب (5 ميغا) مع هامش أمان */
    private const MAX_BYTES = 4_500_000;

    /**
     * نِسَب ترويسة الورقة الرسمية وتذييلها من ارتفاع الصفحة.
     * تُضبط من الإعدادات لأنّ كل ورقة شركة تختلف.
     */
    private const LH_TOP = 21.0;
    private const LH_BOTTOM = 15.0;

    /** نسبة مئوية آمنة ضمن 0..60 */
    private static function percent($value, float $fallback): float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $fallback;
        }

        return max(0.0, min(60.0, (float) $value));
    }

    /**
     * يولّد البطاقة ويحدّث العرض. يعيد المسار النسبي على قرص public.
     *
     * @throws \RuntimeException إذا تعذّر التصيير
     */
    public static function generate(Offer $offer): string
    {
        $offer->loadMissing('options.stays');

        if ($offer->isVisa()) {
            if ($offer->priceFrom() === null) {
                throw new \RuntimeException('لا يمكن توليد بطاقة تأشيرة بلا سعر.');
            }
        } elseif ($offer->sellOptions()->isEmpty()) {
            throw new \RuntimeException('لا يمكن توليد بطاقة لعرض بلا خيارات فنادق بأسعار.');
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
            self::shoot($htmlPath, $pngPath, ...array_slice(self::VARIANTS['a4'], 0, 2));

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
        $offer->loadMissing('options.stays');

        // الخيارات من Offer::sellOptions() — الإسقاط نفسه الذي يقرؤه البوت، فلا تختلف أسعار الصورة عن النص
        return View::make('offers.card', [
            'offer' => $offer,
            'options' => $offer->sellOptions(),
            'supplements' => $offer->supplements(),
            'isVisa' => $offer->isVisa(),
            'showDistance' => $offer->usesHaramDistance(),
            'cities' => $offer->cardCities(),
            'roomTypes' => self::cardRoomLabels(),
            'company' => CompanyInfo::all(),
            'logoUri' => self::dataUri(Setting::get('company_logo')),
            'heroUri' => self::dataUri($offer->hero_path ?: Setting::get('card_hero_path')),
            'minPrice' => $offer->priceFrom(),
            'fontFamily' => self::fontFamily(),
            // ورقة الشركة الرسمية كاملةً خلفيةً للصفحة (تُلغي شريطَي الترويسة والتذييل)
            'letterheadUri' => self::dataUri(Setting::get('card_letterhead_path')),
            'lhTop' => self::percent(Setting::get('card_letterhead_top'), self::LH_TOP),
            'lhBottom' => self::percent(Setting::get('card_letterhead_bottom'), self::LH_BOTTOM),
            // رمز QR مطبوع في التذييل بقرار لكل عرض (E38)
            'qrUri' => self::cardQrUri($offer),
        ])->render();
    }

    /** صورة الرمز المختار للبطاقة (فعّال وصورته محفوظة) كـ data: URI، أو null */
    private static function cardQrUri(Offer $offer): ?string
    {
        if (!$offer->card_qr_link_id) {
            return null;
        }

        return rescue(function () use ($offer) {
            $link = WaQrLink::active()->whereKey($offer->card_qr_link_id)->where('offer_id', $offer->id)->first();

            return $link?->qr_png_path ? self::dataUri($link->qr_png_path) : null;
        }, null, false);
    }

    // ==================== بطاقة الحالة 9:16 (E38) ====================

    /**
     * بيانات قالب الحالة من القائمة البيضاء وحدها: toBotArray وpriceFrom وCompanyInfo. القالب لا يستقبل نموذج العرض،
     * فلا وكيل ولا تكلفة ولا عدد مقاعد ولا جدول فنادق.
     */
    public static function storyData(Offer $o): array
    {
        $bot = $o->toBotArray();
        $company = CompanyInfo::all();
        $phone = collect(preg_split('/[,،\n|]+/u', (string) ($company['phone'] ?? '')))->map(fn ($p) => trim($p))->filter()->first();
        $short = rescue(fn () => WaQrLink::active()->where('offer_id', $o->id)->where('channel', 'status')->orderByDesc('id')->value('deep_link_url'), null, false);

        return [
            'title' => (string) $bot['title'],
            'category' => $bot['category'] ?? null,
            'nights' => $bot['nights'] ?? null,
            'airline' => $bot['airline'] ?? null,
            'trip_date' => $bot['trip_date'] ?? null,
            'valid_to' => $bot['valid_to'] ?? null,
            'includes' => array_values(array_slice(array_filter(array_map('strval', (array) ($bot['includes'] ?? []))), 0, 4)),
            'price_from' => $o->priceFrom(),
            'company' => ['name' => (string) ($company['name'] ?? ''), 'phone' => $phone],
            'logoUri' => self::dataUri(Setting::get('company_logo')),
            'heroUri' => self::dataUri($o->hero_path ?: Setting::get('card_hero_path')),
            'ref' => 'N-'.$o->id,
            'short_link' => is_string($short) ? $short : null,
        ];
    }

    /** HTML بطاقة الحالة (للمعاينة والتوليد) */
    public static function storyHtml(Offer $o): string
    {
        return View::make(self::VARIANTS['story'][2], ['card' => self::storyData($o), 'fontFamily' => self::fontFamily()])->render();
    }

    /**
     * يولّد بطاقة الحالة ويحفظها (offer-cards/story-…png) ويحذف السابقة. لعرض يقرؤه البوت وحده: لا ننشر ما لا يستطيع
     * البوت الإجابة عنه. لا نشر آلي: التنزيل والنشر يدويان.
     *
     * @throws \RuntimeException
     */
    public static function generateStory(Offer $offer): string
    {
        if (!Offer::forBot()->whereKey($offer->id)->exists()) {
            throw new \RuntimeException('لا تنشر عرضاً لا يستطيع البوت الإجابة عنه: راجع شروط الظهور في «ما يراه البوت».');
        }
        $offer->loadMissing('options.stays');
        if ($offer->priceFrom() === null) {
            throw new \RuntimeException('لا يمكن توليد بطاقة حالة لعرض بلا سعر.');
        }

        $tmpDir = storage_path('app/offer-cards-tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }
        $token = $offer->id.'-'.bin2hex(random_bytes(6));
        $htmlPath = "{$tmpDir}/story-{$token}.html";
        $pngPath = "{$tmpDir}/story-{$token}.png";
        file_put_contents($htmlPath, self::storyHtml($offer));

        try {
            self::shoot($htmlPath, $pngPath, ...array_slice(self::VARIANTS['story'], 0, 2));
            if (!is_file($pngPath) || filesize($pngPath) < 1024) {
                throw new \RuntimeException('تعذّر توليد بطاقة الحالة — الملف الناتج فارغ.');
            }
            $target = self::DIR."/story-{$token}.png";
            Storage::disk(self::DISK)->put($target, file_get_contents($pngPath));
            if ($offer->story_card_path && $offer->story_card_path !== $target) {
                Storage::disk(self::DISK)->delete($offer->story_card_path);
            }
            $offer->forceFill(['story_card_path' => $target, 'story_generated_at' => now()])->saveQuietly();

            return $target;
        } finally {
            @unlink($htmlPath);
            @unlink($pngPath);
        }
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

    /** @return list<string> */
    private static function chromiumArguments(string $binary, string $profile, string $htmlPath, string $pngPath, int $width = 1240, int $height = 1754): array
    {
        $args = [
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
            '--window-size=' . $width . ',' . $height,
            '--virtual-time-budget=8000',   // ننتظر الخطوط وسكربت ضبط المقاس
        ];

        // عزل عن الشبكة: بروكسي ميت يشمل loopback (Reverb وphp-fpm وnginx على المضيف نفسه)، وحلّ أسماء فاشل،
        // ولا خدمات خلفية. سياسة CSP في القالب هي الحاجز الأول؛ هذه طبقة ثانية لأي محتوى يفلت منها.
        if (config('services.chromium.net_isolation')) {
            array_push($args,
                '--proxy-server=127.0.0.1:9',
                '--proxy-bypass-list=<-loopback>',
                '--host-resolver-rules=MAP * ~NOTFOUND',
                '--disable-background-networking',
                '--disable-component-update',
                '--disable-sync',
                '--disable-domain-reliability',
                '--no-pings',
            );
        }

        $args[] = '--screenshot=' . $pngPath;
        $args[] = 'file://' . str_replace('\\', '/', $htmlPath);

        return $args;
    }

    /**
     * يشغّل كروميوم بلا واجهة لالتقاط البطاقة. الاختبارات تربط 'offer-card.renderer' (دالة تكتب PNG) فلا كروميوم ولا شبكة.
     */
    private static function shoot(string $htmlPath, string $pngPath, int $width, int $height): void
    {
        if (app()->bound('offer-card.renderer')) {
            app('offer-card.renderer')($htmlPath, $pngPath, $width, $height);

            return;
        }
        $binary = self::chromium();

        // كروميوم يحتاج مجلّد ملفّ تعريف قابلاً للكتابة، و www-data بلا مجلّد منزل
        // داخل الحاوية — فنمنحه مجلّداً مؤقتاً خاصاً بكل عملية ثم نحذفه.
        $profile = sys_get_temp_dir() . '/chromium-' . bin2hex(random_bytes(5));

        $process = new Process(self::chromiumArguments($binary, $profile, $htmlPath, $pngPath, $width, $height));

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
        $configured = config('services.chromium.path');
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
        foreach ([$offer->card_path, $offer->custom_card_path, $offer->hero_path, $offer->story_card_path] as $p) {
            if ($p) {
                Storage::disk(self::DISK)->delete($p);
            }
        }
    }
}
