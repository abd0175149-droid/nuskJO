<?php

namespace App\Http\Controllers;

use App\Services\Security\CspReports;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /csp-report (E26.4): تقارير المتصفحات عن موارد حجبتها سياسة أمن المحتوى أو كانت ستحجبها.
 * عام بلا جلسة ولا CSRF (المتصفح يرسلها بلا كوكي أحياناً)، ومقيّد بالمعدّل (30 في الدقيقة لكل عنوان) والحجم (16KB).
 * لا يحفظ النص الخام: التوجيه ومصدر المحجوب وقالب المسار فقط (CspReports). يرد 204 دائماً، و413 للجسم الكبير.
 */
class CspReportController extends Controller
{
    private const TYPES = ['application/csp-report', 'application/reports+json', 'application/json'];

    public function __invoke(Request $request): Response
    {
        $max = (int) config('csp.report_max_bytes', 16384);
        if ((int) $request->header('Content-Length', '0') > $max) {
            return response('', 413);
        }
        $raw = $request->getContent();
        if (strlen($raw) > $max) {
            return response('', 413);
        }

        $type = strtolower(trim(explode(';', (string) $request->header('Content-Type', ''))[0]));
        if (in_array($type, self::TYPES, true)) {
            CspReports::record(CspReports::extract(json_decode($raw, true, 16)));
        }

        return response('', 204);
    }
}
