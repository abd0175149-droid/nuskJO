<?php

namespace App\Services\Security;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * تقارير CSP من المتصفحات (E26.4)، مجمّعة بلا بيانات شخصية: لكل تقرير ثلاثة أشياء فقط، التوجيه ومصدر المحجوب
 * (مضيفه أو كلمته: inline وeval وdata…) وقالب مسار الصفحة بلا query ولا fragment والأرقام {id}.
 * النص الخام لا يُحفظ (الرابط قد يحمل ?search= بهاتف عميل)، ولا sample. العدّادات في مخزن file
 * (config csp.report_store) بمفتاح يومي يُحفظ 8 أيام، ويُقرأ بالأمر security:csp-report.
 */
final class CspReports
{
    /** مصادر بلا مضيف كما يرسلها المتصفح */
    private const KEYWORDS = ['inline', 'eval', 'wasm-eval', 'data', 'blob', 'self', 'trusted-types-policy', 'trusted-types-sink', 'filesystem', 'mediastream'];

    public static function store(): Repository
    {
        return Cache::store(config('csp.report_store', 'file'));
    }

    public static function dayKey(?string $ymd = null): string
    {
        return 'csp:'.($ymd ?? now()->format('Ymd'));
    }

    /**
     * التقارير من جسم الطلب: صيغة report-uri ({"csp-report": {...}}) أو Reporting API ([{type, body}]).
     *
     * @return list<array{directive: string, source: string, path: string}>
     */
    public static function extract(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $reports = [];
        if (isset($payload['csp-report']) && is_array($payload['csp-report'])) {
            $reports[] = $payload['csp-report'];
        } elseif (array_is_list($payload)) {
            foreach (array_slice($payload, 0, 20) as $item) {
                if (is_array($item) && ($item['type'] ?? null) === 'csp-violation' && is_array($item['body'] ?? null)) {
                    $reports[] = $item['body'];
                }
            }
        }

        $out = [];
        foreach ($reports as $r) {
            $directive = $r['effective-directive'] ?? $r['effectiveDirective'] ?? $r['violated-directive'] ?? $r['violatedDirective'] ?? '';
            $out[] = [
                'directive' => self::directive((string) (is_string($directive) ? $directive : '')),
                'source' => self::source($r['blocked-uri'] ?? $r['blockedURL'] ?? ''),
                'path' => self::pathTemplate($r['document-uri'] ?? $r['documentURL'] ?? ''),
            ];
        }

        return $out;
    }

    /** يضيف التقارير إلى عدّاد اليوم تحت قفل قصير؛ ما بعد سقف الأنماط يُعدّ في «أخرى» */
    public static function record(array $reports): void
    {
        if ($reports === []) {
            return;
        }

        $store = self::store();
        try {
            $store->lock('csp:report:lock', 5)->block(2, function () use ($store, $reports) {
                $key = self::dayKey();
                $day = $store->get($key) ?: ['entries' => [], 'other' => 0];
                $max = (int) config('csp.report_max_distinct', 300);
                foreach ($reports as $r) {
                    $hash = sha1($r['directive'].'|'.$r['source'].'|'.$r['path']);
                    if (isset($day['entries'][$hash])) {
                        $day['entries'][$hash]['count']++;
                    } elseif (count($day['entries']) < $max) {
                        $day['entries'][$hash] = $r + ['count' => 1];
                    } else {
                        $day['other']++;
                    }
                }
                $store->put($key, $day, now()->addDays((int) config('csp.report_days', 8)));
            });
        } catch (LockTimeoutException) {
            // ضغط لحظي: تقرير ضائع أهون من انتظار طلب المتصفح
        }
    }

    /**
     * مجموع آخر N يوماً مرتباً تنازلياً بالعدد.
     *
     * @return array{rows: list<array{directive: string, source: string, path: string, count: int}>, other: int}
     */
    public static function summary(int $days = 7): array
    {
        $rows = [];
        $other = 0;
        for ($i = 0; $i < max(1, $days); $i++) {
            $day = self::store()->get(self::dayKey(now()->subDays($i)->format('Ymd')));
            if (!is_array($day)) {
                continue;
            }
            $other += (int) ($day['other'] ?? 0);
            foreach ($day['entries'] ?? [] as $hash => $r) {
                $rows[$hash] ??= ['directive' => $r['directive'], 'source' => $r['source'], 'path' => $r['path'], 'count' => 0];
                $rows[$hash]['count'] += (int) $r['count'];
            }
        }
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => [$b['count'], $a['path']] <=> [$a['count'], $b['path']]);

        return ['rows' => $rows, 'other' => $other];
    }

    private static function directive(string $value): string
    {
        $value = strtolower(trim(strtok($value, ' ') ?: ''));

        return preg_match('/^[a-z][a-z-]{1,40}$/', $value) ? $value : 'unknown';
    }

    private static function source(mixed $blocked): string
    {
        if (!is_string($blocked) || trim($blocked) === '') {
            return 'inline';
        }
        $blocked = trim($blocked);
        $lower = strtolower($blocked);
        if (in_array($lower, self::KEYWORDS, true)) {
            return $lower;
        }
        $scheme = strtolower((string) parse_url($blocked, PHP_URL_SCHEME));
        if (in_array($scheme, ['data', 'blob', 'filesystem', 'mediastream'], true)) {
            return $scheme;
        }
        $host = strtolower((string) parse_url($blocked, PHP_URL_HOST));
        if ($host !== '' && preg_match('/^[a-z0-9.-]{1,253}$/', $host)) {
            $port = parse_url($blocked, PHP_URL_PORT);

            return ($scheme !== '' ? $scheme.'://' : '').$host.($port ? ':'.$port : '');
        }

        return 'other';
    }

    /** /whatsapp/inbox?search=… ← /whatsapp/inbox، و/clients/15/edit ← /clients/{id}/edit */
    public static function pathTemplate(mixed $uri): string
    {
        if (!is_string($uri) || $uri === '') {
            return '?';
        }
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }

        $segments = [];
        foreach (array_slice(array_values(array_filter(explode('/', $path), fn ($s) => $s !== '')), 0, 8) as $segment) {
            $segment = rawurldecode($segment);
            // مقطع رقمي، أو فيه ستة أرقام فأكثر (هاتف أو معرّف داخل نص): لا يُحفظ
            if (ctype_digit($segment) || preg_match('/\d{6,}/', $segment)) {
                $segments[] = '{id}';
            } else {
                $segments[] = mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '_', $segment) ?? '_', 0, 40);
            }
        }

        return '/'.implode('/', $segments);
    }
}
