<?php

namespace Tests\Unit\Docs;

use App\Services\WhatsApp\GraphVersions;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * أفق إصدار Graph (E25): الإصدار الافتراضي في config/services.php معروف في GraphVersions::SUNSET، ونهايته بعد 60 يوماً
 * على الأقل. لماذا يفشل CI قبل الموعد: بعد نهاية الإصدار تُوجَّه النداءات بصمت إلى أقدم إصدار متاح، فيتغيّر السلوك دون
 * خطأ. التنبيه اليومي whatsapp:graph-version-check يبدأ في اليوم نفسه، والخطوات في docs/RUNBOOK.md RB-07.
 *
 * مجموعة horizon مرتبطة بالتاريخ، فهي خارج بوابة النشر (Dockerfile.test يستثنيها) كي لا يُمنع إصلاح عاجل في يوم
 * بعينه، وتبقى في CI. التجاوز المؤقت بقرار مسجّل في CHANGELOG وحده.
 */
#[Group('horizon')]
class GraphVersionHorizonTest extends TestCase
{
    /** الافتراضي المكتوب في الكود، لا قيمة البيئة */
    public static function codeDefault(): ?string
    {
        $source = (string) file_get_contents(config_path('services.php'));

        return preg_match("/env\\('WA_GRAPH_VERSION'\\)\\s*\\?:\\s*'(v\\d+\\.0)'/", $source, $m) ? $m[1] : null;
    }

    public static function problem(?string $version, Carbon $today): ?string
    {
        if ($version === null || !array_key_exists($version, GraphVersions::SUNSET)) {
            return "إصدار Graph «{$version}» غير موجود في GraphVersions::SUNSET";
        }
        $days = GraphVersions::daysLeft($version, $today);
        if ($days !== null && $days <= GraphVersions::WARN_DAYS) {
            return "إصدار Graph {$version} ينتهي في ".GraphVersions::sunset($version)." (بعد {$days} يوماً): ارفعه بحسب docs/RUNBOOK.md RB-07";
        }

        return null;
    }

    public function test_the_default_graph_version_is_far_from_its_sunset(): void
    {
        $this->assertNull(self::problem(self::codeDefault(), today()));
        $this->assertNull(self::problem(config('services.whatsapp.graph_version'), today()));
    }

    public function test_the_horizon_trips_sixty_days_before_the_sunset(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23'));

        $this->assertNotNull(self::problem('v21.0', today()), 'v21.0 ينتهي 2027-01-21: أقل من 60 يوماً');
        $this->assertNull(self::problem('v25.0', today()));
        $this->assertNotNull(self::problem('v99.0', today()));
    }
}
