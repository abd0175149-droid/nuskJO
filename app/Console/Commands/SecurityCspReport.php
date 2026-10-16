<?php

namespace App\Console\Commands;

use App\Services\Security\CspReports;
use App\Support\Csp;
use Illuminate\Console\Command;

/**
 * ملخص تقارير CSP (E26.4) لأسبوع المراقبة قبل الفرض. قراءة فقط، بلا إشعار: التقارير ضجيج طبيعي من إضافات المتصفحات.
 */
class SecurityCspReport extends Command
{
    protected $signature = 'security:csp-report {--days=7 : عدد الأيام (حتى 8، مدة الاحتفاظ)}';

    protected $description = 'ملخص تقارير سياسة أمن المحتوى: المصدر والمسار والتوجيه والعدد';

    protected $help = <<<'TXT'
كيف تقرأ التقرير قبل التحويل إلى الفرض (العلم csp_enforce أو CSP_MODE=enforce):
- سطر مصدره مضيفنا أو inline على صفحة من التطبيق (/whatsapp/... أو /invoices/...) كسرٌ حقيقي: أصلحه قبل الفرض.
- مصادر مثل chrome-extension أو moz-extension أو مضيفات غريبة على صفحات متفرقة: إضافات متصفح عند الموظفين، ضجيج.
- eval: مكتبة تستعمل eval؛ لا تُضاف 'unsafe-eval' أبداً، بل تُستبدل المكتبة أو تُضبط.
الفرض بعد سبعة أيام لا يظهر فيها إلا الضجيج المعروف. التراجع فوري بإطفاء العلم أو CSP_MODE=report.
TXT;

    public function handle(): int
    {
        $days = max(1, min(8, (int) $this->option('days')));
        $summary = CspReports::summary($days);

        $this->line('وضع السياسة: '.Csp::mode().(Csp::enforced() ? ' (مفروضة)' : ' (تقارير فقط)')." — آخر {$days} أيام");
        if ($summary['rows'] === [] && $summary['other'] === 0) {
            $this->info('لا تقارير.');

            return self::SUCCESS;
        }

        $this->table(['المصدر', 'المسار', 'التوجيه', 'العدد'], array_map(
            fn (array $r) => [$r['source'], $r['path'], $r['directive'], $r['count']],
            $summary['rows'],
        ));
        if ($summary['other'] > 0) {
            $this->line("أخرى (بعد سقف الأنماط اليومي): {$summary['other']}");
        }

        return self::SUCCESS;
    }
}
