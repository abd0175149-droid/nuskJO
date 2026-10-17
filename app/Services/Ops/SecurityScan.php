<?php

namespace App\Services\Ops;

/**
 * ملخص آخر فحص أمني للصورة (E26.11): أعداد فقط، بلا أسماء ملفات ولا حزم ولا مسارات داخلية.
 * يكتبه security:scan-report من سكربت المضيف الأسبوعي، وتعرضه بطاقة الصحة في إعدادات الواتساب.
 * التنبيه للمدراء (OpsAlerts، مرة في الساعة للمفتاح) عند ثغرة حرجة، أو عند زيادة العالية عن الفحص السابق.
 */
final class SecurityScan
{
    public const KEY = 'security:last_scan';

    /** @return array{scanner: string, high: int, critical: int, at: string, previous_high: int|null, alerted: bool} */
    public static function record(string $scanner, int $high, int $critical): array
    {
        $store = OpsHealth::store();
        $previous = $store->get(self::KEY);
        $previousHigh = is_array($previous) && isset($previous['high']) ? (int) $previous['high'] : null;

        $alert = $critical > 0 || ($previousHigh !== null && $high > $previousHigh);
        $summary = [
            'scanner' => $scanner,
            'high' => $high,
            'critical' => $critical,
            'at' => now()->toIso8601String(),
            'previous_high' => $previousHigh,
            'alerted' => $alert,
        ];
        $store->forever(self::KEY, $summary);

        if ($alert) {
            $body = "حرجة {$critical}، عالية {$high}".($previousHigh !== null ? " (كانت {$previousHigh})" : '')
                .'. التقرير على الخادم في ~/security/nuskjo؛ حدّث الحزمة أو بصمة الصورة، أو استثنِ بسبب في .trivyignore.';
            app(OpsAlerts::class)->notifyAdmins('security:scan', '🛡️ فحص الصورة: ثغرات تحتاج تحديثاً', $body);
        }

        return $summary;
    }

    /** للبطاقة: null إن لم يُسجَّل فحص بعد */
    public static function summary(): ?array
    {
        $summary = OpsHealth::store()->get(self::KEY);

        return is_array($summary)
            ? array_intersect_key($summary, array_flip(['scanner', 'high', 'critical', 'at', 'previous_high']))
            : null;
    }
}
