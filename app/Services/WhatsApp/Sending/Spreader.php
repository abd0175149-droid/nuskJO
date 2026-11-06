<?php

namespace App\Services\WhatsApp\Sending;

use Carbon\CarbonInterface;

/**
 * الفاصل بين رسالتين استباقيتين على الرقم نفسه (صيغة b164fb2 في WB web):
 *   spread: clamp(الثواني الباقية في النافذة ÷ الباقي من السقف اليومي، min_gap، 1800) × (0.85 + عشوائي × 0.3)
 *   غير ذلك: min_gap × (1 ± jitter_pct)
 * فتتوزع رسائل اليوم على النافذة كلها بدل دفعة واحدة، ولا يظهر إيقاع آلي ثابت.
 */
final class Spreader
{
    public static function gapSeconds(array $limit, CarbonInterface $now, CarbonInterface $windowEnd, int $usedToday, ?int $dayCap, ?float $rand = null): int
    {
        $min = max(0, (int) ($limit['min_gap_sec'] ?? 0));
        $rand ??= mt_rand() / mt_getrandmax();
        if (!empty($limit['spread']) && $dayCap !== null && $dayCap > $usedToday) {
            $left = max(0, $now->diffInSeconds($windowEnd, false));
            $gap = min(1800, max($min, $left / max(1, $dayCap - $usedToday)));

            return (int) round($gap * (0.85 + $rand * 0.3));
        }
        $jitter = max(0, min(90, (int) ($limit['jitter_pct'] ?? 0))) / 100;

        return (int) round($min * (1 - $jitter + 2 * $jitter * $rand));
    }
}
