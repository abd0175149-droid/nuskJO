<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\Setting;
use App\Services\Rendering\HeadlessChromium;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

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
     * نِسَب ترويسة الورقة الرسمية وتذييلها من ارتفاع الصفحة.
     * تُضبط من الإعدادات لأنّ كل ورقة شركة تختلف.
     */
    public const LH_TOP = 21.0;
    public const LH_BOTTOM = 15.0;

    /** نسبة مئوية آمنة ضمن 0..60 (تستعملها فاتورة PDF أيضاً، E32) */
    public static function percent($value, float $fallback): float
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

    /** يحوّل ملفاً على قرص public إلى data: URI ليعمل القالب بلا شبكة (وقالب فاتورة PDF، E32) */
    public static function dataUri(?string $path): ?string
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

    /** @return list<string> أعلام الالتقاط (المُشغّل المشترك HeadlessChromium منذ E32، بالترتيب نفسه) */
    private static function chromiumArguments(string $binary, string $profile, string $htmlPath, string $pngPath): array
    {
        return HeadlessChromium::screenshotArguments($binary, $profile, $htmlPath, $pngPath, self::WIDTH, self::HEIGHT);
    }

    /** يشغّل كروميوم بلا واجهة لالتقاط البطاقة (ملف تعريف مؤقت ومهلة 60 ثانية في HeadlessChromium) */
    private static function shoot(string $htmlPath, string $pngPath): void
    {
        HeadlessChromium::screenshot($htmlPath, $pngPath, self::WIDTH, self::HEIGHT, 'البطاقة');
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
