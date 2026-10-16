<?php

namespace App\Console\Commands;

use App\Models\Offer;
use App\Services\OfferCardService;
use Illuminate\Console\Command;

/**
 * اختبار دخان لتوليد البطاقة (E26.8)، من scripts/smoke.sh بعد كل نشر:
 *   docker exec -u www-data nuskjo-app php artisan ops:smoke-card
 * يصيّر قالب البطاقة الحقيقي لعرض ثابت غير محفوظ عبر كروميوم إلى ملف مؤقت، ويتحقق أن PNG أكبر من 20KB وبالأبعاد
 * الصحيحة، ثم يحذفه. لا يكتب في القاعدة ولا في القرص العام ولا يرسل شيئاً لأحد. يطبع زمن التصيير والذاكرة لضبط mem_limit.
 */
class OpsSmokeCard extends Command
{
    protected $signature = 'ops:smoke-card {--keep : يُبقي الصورة ويطبع مسارها (فحص يدوي)}';

    protected $description = 'اختبار دخان: تصيير بطاقة ثابتة بكروميوم والتحقق منها ثم حذفها (لا يرسل شيئاً)';

    private const MIN_BYTES = 20 * 1024;

    public function handle(): int
    {
        $png = sys_get_temp_dir().'/smoke-card-'.bin2hex(random_bytes(6)).'.png';
        $started = hrtime(true);

        try {
            OfferCardService::renderHtml(OfferCardService::html(self::fixture()), $png);
        } catch (\Throwable $e) {
            $this->error('❌ تعذّر تصيير البطاقة: '.$e->getMessage());

            return self::FAILURE;
        }

        $ms = (int) round((hrtime(true) - $started) / 1e6);
        $bytes = is_file($png) ? (int) filesize($png) : 0;
        [$width, $height] = ($bytes > 0 ? @getimagesize($png) : false) ?: [0, 0];

        $this->line(sprintf('البطاقة: %d×%d، %s KB، التصيير %d ms', $width, $height, number_format($bytes / 1024, 1), $ms));
        $this->line(sprintf('ذاكرة PHP القصوى: %s MB', number_format(memory_get_peak_usage(true) / 1048576, 1)));
        if ($peak = self::containerPeakBytes()) {
            $this->line(sprintf('ذروة ذاكرة الحاوية منذ إقلاعها: %s MB (لضبط mem_limit: ضعفها ولا تقل عن 2g)', number_format($peak / 1048576)));
        }

        if ($this->option('keep')) {
            $this->line("الصورة: {$png}");
        } else {
            @unlink($png);
        }

        $ok = $bytes > self::MIN_BYTES && $width === OfferCardService::WIDTH && $height === OfferCardService::HEIGHT;
        $ok ? $this->info('✅ توليد البطاقة يعمل') : $this->error('❌ البطاقة فارغة أو بأبعاد خاطئة');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** عرض تأشيرة ثابت في الذاكرة: لا يُحفظ، وعلاقاته فارغة محمّلة فلا يُسأل عن خيارات أو مجموعات */
    private static function fixture(): Offer
    {
        $offer = (new Offer)->forceFill([
            'title' => 'تأشيرة سياحية — بطاقة اختبار الدخان',
            'category' => 'visa',
            'price_per_person' => 45,
            'visa_validity' => '90 يوماً من تاريخ الإصدار',
            'visa_entries' => 'دخول واحد',
            'visa_processing' => 'من 5 إلى 7 أيام عمل',
            'description_client' => "بطاقة ثابتة يولّدها اختبار الدخان بعد كل نشر للتحقق من كروميوم والخطوط العربية.\nلا تُرسل لأحد وتُحذف فوراً.",
            'requirements' => "جواز سفر ساري المفعول ستة أشهر على الأقل\nصورة شخصية بخلفية بيضاء\nكشف حساب بنكي لآخر ثلاثة أشهر",
            'notes_public' => 'الأسعار إرشادية حتى تأكيد الموظف وإصدار الفاتورة.',
            'includes' => ['رسوم التأشيرة', 'تعبئة الطلب ومتابعته', 'التأمين الصحي للمدة'],
            'excludes' => ['تذاكر الطيران', 'الإقامة'],
        ]);
        $offer->setRelation('options', collect());
        $offer->setRelation('groups', collect());

        return $offer;
    }

    /** ذروة ذاكرة الحاوية من cgroup (v2 ثم v1)، أو null خارج الحاوية */
    private static function containerPeakBytes(): ?int
    {
        foreach (['/sys/fs/cgroup/memory.peak', '/sys/fs/cgroup/memory/memory.max_usage_in_bytes'] as $file) {
            $value = is_readable($file) ? trim((string) @file_get_contents($file)) : '';
            if (ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }
}
