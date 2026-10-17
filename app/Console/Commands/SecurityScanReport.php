<?php

namespace App\Console\Commands;

use App\Services\Ops\SecurityScan;
use Illuminate\Console\Command;

/**
 * يسجّل ملخص فحص الصورة (E26.11)، من scripts/security/trivy-scan.sh على المضيف:
 *   docker exec -u www-data nuskjo-app php artisan security:scan-report --high=N --critical=M --scanner=trivy
 * أعداد فقط؛ التقرير الكامل وSBOM يبقيان على المضيف.
 */
class SecurityScanReport extends Command
{
    protected $signature = 'security:scan-report
        {--high= : عدد الثغرات العالية}
        {--critical= : عدد الثغرات الحرجة}
        {--scanner=trivy : أداة الفحص}';

    protected $description = 'تسجيل ملخص آخر فحص أمني للصورة (أعداد فقط) وتنبيه المدراء عند الحاجة';

    public function handle(): int
    {
        $high = $this->option('high');
        $critical = $this->option('critical');
        $scanner = (string) $this->option('scanner');

        if (!is_string($high) || !ctype_digit($high) || !is_string($critical) || !ctype_digit($critical)) {
            $this->error('--high و--critical عددان صحيحان غير سالبين');

            return self::INVALID;
        }
        if (!preg_match('/^[a-z0-9_-]{1,20}$/', $scanner)) {
            $this->error('--scanner اسم قصير بحروف لاتينية صغيرة');

            return self::INVALID;
        }

        $summary = SecurityScan::record($scanner, (int) $high, (int) $critical);
        $this->info("سُجّل فحص {$scanner}: حرجة {$summary['critical']}، عالية {$summary['high']}".($summary['alerted'] ? ' — نُبّه المدراء' : ''));

        return self::SUCCESS;
    }
}
