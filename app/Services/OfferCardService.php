<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\Setting;
use App\Models\WaQrLink;
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
    /** أبعاد البطاقة: A4 بدقّة 150 نقطة/البوصة (عامة لاختبار الدخان ops:smoke-card) */
    public const WIDTH = 1240;
    public const HEIGHT = 1754;

    /**
     * المقاسات: A4 بدقّة 150 نقطة/البوصة، وبطاقة الحالة 9:16 (E38) للنشر اليدوي. [العرض، الارتفاع، القالب]
     */
    public const VARIANTS = [
        'a4' => [self::WIDTH, self::HEIGHT, 'offers.card'],
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

    /**
     * HTML البطاقة — يُستخدم أيضاً للمعاينة في المتصفّح قبل التوليد. nonce جديد لكل تصيير ما لم يُمرَّر
     * (المعاينة تمرّره لتضع السياسة نفسها ترويسةً): سكربت ضبط المقاس وحده ينفَّذ، ولا سكربت آخر ولو حُقن نص.
     */
    public static function html(Offer $offer, ?string $nonce = null): string
    {
        $offer->loadMissing('options.stays');
        $nonce ??= bin2hex(random_bytes(16));

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
            'cspNonce' => $nonce,
            'csp' => self::cardPolicy($nonce),
            // رمز QR مطبوع في التذييل بقرار لكل عرض (E38)
            'qrUri' => self::cardQrUri($offer),
        ])->render();
    }

    /**
     * سياسة قالب البطاقة (E05، وشُدّدت في E26): بلا شبكة ولا ملفات، الصور data: والخطوط خطوط النظام،
     * والسكربت الوحيد بـ nonce بدل 'unsafe-inline'. style-src مضمّن لأن الخلفيات data: في خصائص style.
     */
    public static function cardPolicy(string $nonce): string
    {
        return "default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'nonce-{$nonce}'; font-src data:; base-uri 'none'; form-action 'none'";
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
    private static function chromiumArguments(string $binary, string $profile, string $htmlPath, string $pngPath, int $width = self::WIDTH, int $height = self::HEIGHT): array
    {
        return HeadlessChromium::screenshotArguments($binary, $profile, $htmlPath, $pngPath, $width, $height);
    }

    /**
     * يصيّر HTML جاهزاً إلى PNG في المسار المعطى بالطريق نفسه (كروميوم والعزل)، بلا قرص ولا عرض.
     * لاختبار الدخان ops:smoke-card (E26.8)؛ ملف HTML المؤقت في sys_get_temp_dir() ويُحذف دائماً.
     */
    public static function renderHtml(string $html, string $pngPath): void
    {
        $htmlPath = sys_get_temp_dir().'/card-smoke-'.bin2hex(random_bytes(6)).'.html';
        file_put_contents($htmlPath, $html);
        try {
            self::shoot($htmlPath, $pngPath, self::WIDTH, self::HEIGHT);
        } finally {
            @unlink($htmlPath);
        }
    }

    /**
     * يشغّل كروميوم بلا واجهة لالتقاط البطاقة (ملف تعريف مؤقت ومهلة 60 ثانية في HeadlessChromium).
     * الاختبارات تربط 'offer-card.renderer' (دالة تكتب PNG) فلا كروميوم ولا شبكة (E38).
     */
    private static function shoot(string $htmlPath, string $pngPath, int $width, int $height): void
    {
        if (app()->bound('offer-card.renderer')) {
            app('offer-card.renderer')($htmlPath, $pngPath, $width, $height);

            return;
        }
        HeadlessChromium::screenshot($htmlPath, $pngPath, $width, $height, 'البطاقة');
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
