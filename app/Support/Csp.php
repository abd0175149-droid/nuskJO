<?php

namespace App\Support;

use Illuminate\Support\Facades\Vite;

/**
 * سياسة أمن المحتوى لصفحات التطبيق (E26.3). سياسة واحدة بـ nonce لكل طلب، يحمله كل وسم من @vite وسكربت الثيم و@routes.
 * - لا 'unsafe-eval': Vue مبني للتشغيل وحده، وpdf.js يعمل بـ isEvalSupported:false.
 * - لا مضيف خارجي في script-src: pdf.js والخطوط من /build (E26.2، E16.8).
 * - style-src يقبل المضمَّن: مئات خصائص style في القوالب ونمط شريط تقدّم Inertia؛ النمط لا ينفّذ سكربتاً.
 * - connect-src: Reverb على مضيف APP_URL نفسه (wss)، وتسجيل إشعارات Firebase على googleapis.
 * الترويسات الثابتة (HSTS وX-Frame-Options وnosniff وReferrer-Policy وPermissions-Policy وCOOP) من nginx لا من هنا.
 */
final class Csp
{
    public const MODES = ['off', 'report', 'enforce'];

    public static function mode(): string
    {
        $mode = strtolower(trim((string) config('csp.mode', 'report')));

        return in_array($mode, self::MODES, true) ? $mode : 'report';
    }

    /** الفرض: CSP_MODE=enforce، أو العلم csp_enforce فوق وضع التقارير. فشل قراءة العلم = تقارير */
    public static function enforced(): bool
    {
        return match (self::mode()) {
            'enforce' => true,
            'report' => (bool) rescue(fn () => FeatureFlags::on('csp_enforce'), false, false),
            default => false,
        };
    }

    /** اسم الترويسة حسب الوضع، أو null حين تُطفأ */
    public static function header(): ?string
    {
        if (self::mode() === 'off') {
            return null;
        }

        return self::enforced() ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
    }

    /** @return array<string, list<string>> */
    public static function directives(string $nonce): array
    {
        $connect = ["'self'"];
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $connect[] = 'wss://'.$host;
        }
        $connect[] = 'https://*.googleapis.com';
        foreach ((array) config('csp.connect_extra', []) as $source) {
            // مصدر واحد بلا فراغ ولا فاصلة منقوطة: قيمة البيئة لا تكسر الترويسة ولا تضيف توجيهاً
            if (is_string($source) && preg_match('#^(https?|wss?)://[A-Za-z0-9.*\-]+(:\d+)?(/[^\s;,\'"]*)?$#', $source)) {
                $connect[] = $source;
            }
        }

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'font-src' => ["'self'", 'data:'],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'connect-src' => $connect,
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
            'frame-src' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
        ];

        // خادم Vite للتطوير (npm run dev، الملف public/hot): أصله لسكربتات HMR وأنماطها واتصالها. لا وجود له في الإنتاج
        if ($hot = self::hotOrigin()) {
            foreach (['script-src', 'style-src', 'font-src', 'img-src'] as $name) {
                $directives[$name][] = $hot;
            }
            $directives['connect-src'][] = $hot;
            $directives['connect-src'][] = preg_replace('#^http#', 'ws', $hot);
        }

        return array_map(fn (array $sources) => array_values(array_unique($sources)), $directives);
    }

    public static function policy(string $nonce): string
    {
        $parts = [];
        foreach (self::directives($nonce) as $name => $sources) {
            $parts[] = $name.' '.implode(' ', $sources);
        }
        $parts[] = 'report-uri '.config('csp.report_uri', '/csp-report');
        $parts[] = 'report-to csp';

        return implode('; ', $parts);
    }

    private static function hotOrigin(): ?string
    {
        $vite = Vite::getFacadeRoot();
        if (!$vite instanceof \Illuminate\Foundation\Vite || !$vite->isRunningHot()) {
            return null;
        }
        $url = trim((string) @file_get_contents($vite->hotFile()));
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }
        $host = str_contains($parts['host'], ':') && !str_starts_with($parts['host'], '[') ? '['.$parts['host'].']' : $parts['host'];

        return $parts['scheme'].'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
